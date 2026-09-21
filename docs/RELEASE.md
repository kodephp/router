# 发布指南

本文档说明如何发布 `kode/router` 的新版本。

## 版本号来自 git tag

`composer.json` **不写** `version` 字段——Packagist 与 Composer 以仓库的 git tag 为准
（`v2.1.0` 对应 `2.1.0.0`）。仓库里也不保留 `CHANGELOG.md`（已随"精简仓库"移除），
对外契约变化写进 `README.md` 与 `docs/API.md`。

## 发布前检查

```bash
composer test                                        # 等价于 phpunit tests
php -d memory_limit=1G vendor/bin/phpstan analyse src --level=8 --no-progress
```

两条都必须全绿。仓库不含 `phpstan.neon` / `.php-cs-fixer.dist.php`（同样已移除），
所以静态分析要显式带上目录与级别；`phpunit` 也没有配置文件，测试目录由命令行给出。

## 发布步骤

```bash
git checkout main
git pull origin main

# 1) 先提交代码与文档改动（README.md / docs/API.md 需同步新行为）
git add -A && git commit -m "fix: ..."

# 2) 打标签并推送（tag 名与 commit 分开推，避免一次失败留半成品）
git tag v2.1.1
git push origin main
git push origin v2.1.1
```

Packagist 侧若仓库已勾过自动订阅，push tag 即触发新版本；否则到
<https://packagist.org/packages/kode/router> 手动 "Update"。

## 版本策略

语义化版本：

| 变更 | 例子 |
|------|------|
| 只修 bug，行为不变 | `2.1.0 → 2.1.1` |
| 新增向下兼容能力 | `2.1.1 → 2.2.0` |
| 改动已有行为契约 | 升主版本，并在 `README.md` 显式写出新契约 |

> 2.2.0 即按第三行处理：路径匹配改为「编码态匹配 + 命中后解码参数」，
> 含非 ASCII 的字面量路由段从此配不上编码态请求。详见 README「路径编码与参数解码」。

## 回滚

```bash
git push --delete origin v2.1.1        # 撤 tag
```

代码回滚走 `git revert`，不要 `reset --hard` 后强推。
