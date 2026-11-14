#!/usr/bin/env python3
"""Foldx image host local vision assistant.

Loads a quantized CLIP vision/text encoder + NudeNet once, exposes a private
JSON endpoint on 127.0.0.1 so PHP uploads can be moderated without a third-party
API key. The service only binds to localhost.
"""
from __future__ import annotations

import json
import os
import sys
import time
import traceback
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

MODEL_DIR = Path(os.environ.get("FOLDX_AI_MODEL_DIR", "/opt/foldx-ai/models"))
HOST = os.environ.get("FOLDX_AI_HOST", "127.0.0.1")
PORT = int(os.environ.get("FOLDX_AI_PORT", "8791"))
MAX_BYTES = int(os.environ.get("FOLDX_AI_MAX_BYTES", str(22 * 1024 * 1024)))

os.environ.setdefault("OMP_NUM_THREADS", "2")
os.environ.setdefault("ORT_NUM_THREADS", "2")

import numpy as np
from PIL import Image, ImageOps
import onnxruntime as ort
from tokenizers import Tokenizer
from nudenet import NudeDetector

CATEGORY_LABELS = [
    ("anime illustration, manga art, cel shaded anime", "二次元"),
    ("digital painting, concept art, illustration", "插画"),
    ("photograph of a person, portrait, selfie", "人物"),
    ("landscape photograph, nature, scenery", "风景"),
    ("cityscape, architecture photograph", "建筑"),
    ("website screenshot, user interface, app screen", "截图"),
    ("chat conversation screenshot, text message", "截图"),
    ("document scan, page of text, receipt", "文档"),
    ("game screenshot, video game render", "游戏"),
    ("internet meme, reaction image, funny picture", "表情包"),
    ("product photograph, ecommerce still life", "素材"),
    ("food photograph, meal, dessert", "美食"),
    ("animal photograph, pet, wildlife", "动物"),
    ("abstract graphic design, geometric pattern", "设计"),
    ("desktop wallpaper, scenic background", "壁纸"),
    ("other, miscellaneous image", "其他"),
]

EXPLICIT = {
    "FEMALE_BREAST_EXPOSED", "FEMALE_GENITALIA_EXPOSED",
    "MALE_GENITALIA_EXPOSED", "ANUS_EXPOSED", "BUTTOCKS_EXPOSED",
    "MALE_BREAST_EXPOSED",
}
SUGGESTIVE = {"BELLY_EXPOSED", "FEET_EXPOSED", "ARMPITS_EXPOSED", "FEMALE_BREAST_COVERED"}

MODEL_LABEL_CN = {
    "anime illustration, manga art, cel shaded anime": "二次元",
    "digital painting, concept art, illustration": "插画",
    "photograph of a person, portrait, selfie": "人物",
    "landscape photograph, nature, scenery": "风景",
    "cityscape, architecture photograph": "建筑",
    "website screenshot, user interface, app screen": "截图",
    "chat conversation screenshot, text message": "聊天截图",
    "document scan, page of text, receipt": "文档",
    "game screenshot, video game render": "游戏",
    "internet meme, reaction image, funny picture": "表情包",
    "product photograph, ecommerce still life": "商品",
    "food photograph, meal, dessert": "美食",
    "animal photograph, pet, wildlife": "动物",
    "abstract graphic design, geometric pattern": "设计",
    "desktop wallpaper, scenic background": "壁纸",
    "other, miscellaneous image": "其他",
}


def _softmax(x: np.ndarray) -> np.ndarray:
    x = x - np.max(x)
    e = np.exp(x)
    return e / max(1e-12, e.sum())


class Moderator:
    def __init__(self) -> None:
        t0 = time.time()
        print("[foldx-ai] loading NudeNet", flush=True)
        self.nudenet = NudeDetector()
        print("[foldx-ai] loading CLIP vision", flush=True)
        so = ort.SessionOptions()
        so.intra_op_num_threads = 2
        so.inter_op_num_threads = 1
        self.vision = ort.InferenceSession(
            str(MODEL_DIR / "vision_model_quantized.onnx"),
            sess_options=so,
            providers=["CPUExecutionProvider"],
        )
        print("[foldx-ai] loading CLIP text", flush=True)
        self.text = ort.InferenceSession(
            str(MODEL_DIR / "text_model_quantized.onnx"),
            sess_options=so,
            providers=["CPUExecutionProvider"],
        )
        self.tokenizer = Tokenizer.from_file(str(MODEL_DIR / "tokenizer.json"))
        self.tokenizer.enable_truncation(max_length=77)
        # CLIP's learned pad token is <|endoftext|>.
        self.tokenizer.enable_padding(length=77, pad_id=49407)
        self.text_input = self.text.get_inputs()[0].name
        self.vision_input = self.vision.get_inputs()[0].name
        self.text_embeds = self._embed_texts([label for label, _ in CATEGORY_LABELS])
        print(f"[foldx-ai] ready in {time.time() - t0:.1f}s", flush=True)

    def _embed_texts(self, texts: list[str]) -> np.ndarray:
        enc = self.tokenizer.encode_batch(texts)
        ids = np.asarray([e.ids for e in enc], dtype=np.int64)
        out = self.text.run(None, {self.text_input: ids})[0]
        out = np.asarray(out, dtype=np.float32)
        out /= np.maximum(np.linalg.norm(out, axis=-1, keepdims=True), 1e-12)
        return out

    @staticmethod
    def _preprocess(path: str) -> np.ndarray:
        with Image.open(path) as im:
            im = ImageOps.exif_transpose(im).convert("RGB")
            # CLIP: shortest edge resize to 224 then center crop 224.
            w, h = im.size
            scale = 224.0 / min(w, h)
            nw, nh = max(224, int(round(w * scale))), max(224, int(round(h * scale)) )
            im = im.resize((nw, nh), Image.Resampling.BICUBIC)
            left, top = (nw - 224) // 2, (nh - 224) // 2
            im = im.crop((left, top, left + 224, top + 224))
            arr = np.asarray(im, dtype=np.float32) / 255.0
        mean = np.asarray([0.48145466, 0.4578275, 0.40821073], dtype=np.float32)
        std = np.asarray([0.26862954, 0.26130258, 0.27577711], dtype=np.float32)
        arr = (arr - mean) / std
        return np.transpose(arr, (2, 0, 1))[None, ...].astype(np.float32)

    def moderate(self, path: str) -> dict:
        if not os.path.isfile(path):
            raise FileNotFoundError(path)
        if os.path.getsize(path) > MAX_BYTES:
            return {"safe": False, "category": "文件过大", "tags": [], "engine": "local",
                    "reason": "图片超过本地审核服务大小上限"}
        try:
            detections = self.nudenet.detect(path)
        except Exception:
            detections = []
        explicit = []
        suggestive = []
        for det in detections or []:
            cls = str(det.get("class", ""))
            score = float(det.get("score", 0) or 0)
            if score < 0.35:
                continue
            if cls in EXPLICIT:
                explicit.append({"label": cls, "score": round(score, 3)})
            elif cls in SUGGESTIVE:
                suggestive.append({"label": cls, "score": round(score, 3)})

        vec = self.vision.run(None, {self.vision_input: self._preprocess(path)})[0][0]
        vec = np.asarray(vec, dtype=np.float32)
        vec /= max(1e-12, float(np.linalg.norm(vec)))
        scores = self.text_embeds @ vec
        top_idx = int(np.argmax(scores))
        top_label = CATEGORY_LABELS[top_idx][0]
        category = CATEGORY_LABELS[top_idx][1]
        top_score = float(scores[top_idx])
        top3 = np.argsort(scores)[::-1][:3]
        tags = [CATEGORY_LABELS[int(i)][1] for i in top3]
        # De-duplicate while keeping order.
        tags = list(dict.fromkeys(tags))

        if explicit:
            safe = False
            category = "敏感内容"
            tags = ["敏感"] + [e["label"].lower().replace("_", " ") for e in explicit[:2]]
            reason = "本地模型检测到可能违规的裸露内容"
        else:
            safe = True
            reason = "通过本地视觉模型审核"
            if top_score < 0.18:
                category = "其他"
        if safe and category not in ("截图", "文档", "敏感内容") and "二次元" in tags:
            # CLIP often returns “illustration” for anime; keep the more useful
            # human-facing category when anime is one of the top matches.
            category = "二次元"

        if suggestive and safe:
            tags = tags + ["人物暴露"]
            reason = "通过审核，含轻微暴露标签"

        return {
            "safe": safe,
            "category": category,
            "tags": tags[:5],
            "score": round(top_score, 3),
            "detections": explicit + suggestive,
            "engine": "clip-vit-b32+nudenet",
            "reason": reason,
        }


MODERATOR: Moderator | None = None


class Handler(BaseHTTPRequestHandler):
    server_version = "FoldxVision/1.0"

    def log_message(self, fmt, *args):
        sys.stdout.write("[http] " + (fmt % args) + "\n")
        sys.stdout.flush()

    def _send(self, code: int, obj: dict) -> None:
        body = json.dumps(obj, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path.split("?")[0] == "/health":
            self._send(200, {"ok": MODERATOR is not None, "engine": "clip-vit-b32+nudenet"})
        else:
            self._send(404, {"ok": False, "error": "not found"})

    def do_POST(self):
        if self.path.split("?")[0] != "/moderate":
            self._send(404, {"ok": False, "error": "not found"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0") or 0)
            if length <= 0 or length > 4096:
                self._send(400, {"ok": False, "error": "bad request"})
                return
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
            path = str(payload.get("path", ""))
            result = MODERATOR.moderate(path) if MODERATOR else {"safe": False, "category": "审核服务未就绪", "tags": [], "reason": "AI 服务未就绪"}
            self._send(200, {"ok": True, "result": result})
        except FileNotFoundError:
            self._send(200, {"ok": True, "result": {"safe": False, "category": "无法读取", "tags": [], "reason": "文件不存在"}})
        except Exception as exc:
            traceback.print_exc()
            self._send(500, {"ok": False, "error": str(exc)})


def main():
    global MODERATOR
    MODERATOR = Moderator()
    server = ThreadingHTTPServer((HOST, PORT), Handler)
    print(f"[foldx-ai] listening on {HOST}:{PORT}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
