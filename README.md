# Foldx 图床

一个自托管的图片托管站：**任何人不用登录就能上传**，上传时先过一遍本地视觉模型，审核通过后按内容自动分类。原图落在自己的服务器上，直链不经过第三方。

线上：<https://img.foldx.cn/>  
后台：<https://img.foldx.cn/admin/>

![Foldx 图床首页](https://img.foldx.cn/i/2026/11/14/97acc2b420.png)

![后台图片库](https://img.foldx.cn/i/2026/11/14/f95b3f1433.png)

## 它现在能做什么

- **匿名公开上传**：拖拽、粘贴、批量选择；不需要账号，不排队等人工审核。
- **AI 审核**：NudeNet 检查裸露内容，CLIP 做零样本分类；审核不通过的图片不落盘。
- **自动分类与标签**：二次元、截图、文档、人物、风景、表情包等；图库按分类筛选。
- **随机二次元壁纸**：首页、登录页、后台每次打开随机换一张，素材就是主站那批壁纸。
- **后台只负责管理**：`/admin/` 里查看统计、搜索、复制直链、删除；管理员登录不参与公开上传。
- **公开 API**：`/api/images.php?public=1` 可以匿名读取最近图片元数据。
- **单文件 SQLite**：图片元数据在 `images.sqlite`，原图在独立目录，升级不需要数据库服务。

## 技术结构

```
PHP 8.3 + SQLite
├── index.php / login.php / admin/index.php   页面
├── api/upload.php                            公开上传 + AI 审核
├── api/images.php / delete.php / stats.php   图片库与管理接口
├── lib.php                                   配置、数据库、缩略图、审核调用
├── assets/style.css + brand.css              基础样式 + 壁纸视觉层
└── ai/moderator.py                           本机 CLIP + NudeNet 服务
```

### 上传链路

1. 浏览器从首页拿 CSRF token，直接 `POST /api/upload.php`。
2. 服务端检查 MIME、尺寸、大小、单 IP 频率。
3. 把临时文件路径交给 `127.0.0.1:8791/moderate`。
4. 审核服务返回 `{safe, category, tags, reason}`。
5. 通过才落盘，同时写入分类、标签、审核状态；不通过直接丢弃临时文件。
6. 生成缩略图，返回原图直链。

### AI 审核与分类

- **NudeNet**：本地 ONNX 模型，输出裸露类检测框；超过阈值直接拒绝。
- **CLIP ViT-B/32**：在 224×224 上做图像编码，与预设的英文类别描述做余弦相似度，取 top-3 作为分类和标签。
- 全部在本机 CPU 上跑，不把用户图片发给第三方；服务只监听 `127.0.0.1`。
- 审核服务连不上时按“不通过”处理，公开上传不会因为服务挂了而绕过审核。

模型文件不进仓库，下载方式见 [`ai/models/README.md`](ai/models/README.md)。

## 部署

### 1. 目录和配置

把 Web 根目录放在 `/www/wwwroot/img.example.com`，数据目录放在 Web 根之外，例如 `/www/wwwroot/imgfoldx-data`：

```bash
cp config.example.php /www/wwwroot/imgfoldx-data/config.php
php -r "echo password_hash('replace-me', PASSWORD_DEFAULT), PHP_EOL;"
```

把生成的 hash 填进 `config.php` 的 `admin_password_hash`，并按自己的域名改 `base_url`、`storage_dir`、`db_path`。

### 2. PHP / Nginx

- PHP 8.3，启用 `curl`、`fileinfo`、`gd`、`pdo_sqlite`、`sqlite3`。
- Nginx 示例见 [`deploy/nginx.conf.example`](deploy/nginx.conf.example)。
- PHP-FPM 建议 `upload_max_filesize=20M`、`post_max_size=25M`；Nginx `client_max_body_size 25m`。

### 3. 审核服务

```bash
pip3 install --break-system-packages -r ai/requirements.txt
sudo mkdir -p /opt/foldx-ai
sudo cp ai/moderator.py /opt/foldx-ai/
sudo cp -r ai/models /opt/foldx-ai/
sudo cp ai/foldx-vision.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now foldx-vision
curl http://127.0.0.1:8791/health
```

服务默认使用 `/opt/foldx-ai/models`；换路径时设置 `FOLDX_AI_MODEL_DIR`。

### 4. 壁纸

把二次元壁纸放进 `assets/bg/`（`.jpg/.png/.webp`）。每次 PHP 渲染页面随机选一张，目录为空时退回纯色背景。仓库不附带壁纸，原因见 [`assets/bg/README.md`](assets/bg/README.md)。

## 配置项

| 字段 | 说明 |
|---|---|
| `admin_user` / `admin_password_hash` | 后台账号；只用 PHP `password_hash()` 生成的 hash |
| `max_file_size` / `max_upload_count` | 管理员上传上限 |
| `public_max_files` / `public_max_file_size` | 匿名单次上限 |
| `public_hour_limit` / `public_day_limit` | 匿名频率限制 |
| `ai_moderation_enabled` | 是否启用审核 |
| `ai_moderation_url` | 本机审核服务地址 |
| `storage_dir` | 原图目录，放在 Web 根之外 |
| `db_path` | SQLite 文件路径 |

## 安全边界

- 公开上传有单 IP 频率限制（默认 15 次/小时、50 次/天），按请求计数。
- 审核不通过或审核服务异常都不会保存文件。
- 后台删除需要登录 + CSRF；公开接口只读元数据。
- 管理员密码只存在于 Web 根之外的 `config.php`，仓库不包含真实配置。
- 这个项目是个人图床，不是多租户对象存储；没有配额、团队、签名 URL 等能力。

## License

MIT。壁纸素材不随仓库分发，请使用自己拥有授权的图片。
