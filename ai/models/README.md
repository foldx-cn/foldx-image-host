# 模型文件

仓库不包含二进制权重。部署时把模型放进本目录，或用 `FOLDX_AI_MODEL_DIR` 指向别的目录：

- `vision_model_quantized.onnx`、`text_model_quantized.onnx`、`tokenizer.json`、`preprocessor_config.json`、`config.json`：来自 `Xenova/clip-vit-base-patch32`。
- `nudenet` 自带 `320n.onnx`：安装 `nudenet` 后自动可用。

```bash
mkdir -p /opt/foldx-ai/models
cd /opt/foldx-ai/models
for f in vision_model_quantized.onnx text_model_quantized.onnx; do
  curl -fL "https://huggingface.co/Xenova/clip-vit-base-patch32/resolve/main/onnx/$f" -o "$f"
done
for f in tokenizer.json preprocessor_config.json config.json; do
  curl -fL "https://huggingface.co/Xenova/clip-vit-base-patch32/resolve/main/$f" -o "$f"
done
```
