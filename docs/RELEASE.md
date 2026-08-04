# 发布指南

本文档说明如何发布 `kode/router` 的新版本。

## 发布前检查

### 1. 运行所有测试

```bash
composer test
```

确保所有测试通过。

### 2. 运行静态分析

```bash
composer phpstan
```

确保没有错误。

### 3. 检查代码风格

```bash
composer cs-fix --dry-run
```

确保代码风格符合规范。

### 4. 更新文档

- [ ] 更新 README.md
- [ ] 更新 CHANGELOG.md
- [ ] 更新 API 文档

## 发布步骤

### 1. 创建发布分支

```bash
git checkout main
git pull origin main
git checkout -b release/v1.0.0
```

### 2. 更新版本号

修改 `composer.json` 中的版本号：

```json
{
    "name": "kode/router",
    "version": "1.0.0"
}
```

### 3. 更新 CHANGELOG

编辑 `CHANGELOG.md`：

```markdown
## [1.0.0] - 2024-08-03

### Added
- 初始版本发布
- 支持路由定义和分发
- 支持命名路由
- 支持路由组
- 支持中间件
```

### 4. 运行完整测试

```bash
composer ci
```

### 5. 提交更改

```bash
git add .
git commit -m "Release v1.0.0"
git push origin release/v1.0.0
```

### 6. 创建 Pull Request

在 GitHub 上创建 Pull Request，合并到 main 分支。

### 7. 创建标签

```bash
git tag v1.0.0
git push origin v1.0.0
```

### 8. 发布到 Packagist

1. 登录 [Packagist](https://packagist.org/)
2. 点击 "Submit"
3. 输入仓库 URL：`https://github.com/zhuanz/kode-router`
4. 点击 "Test" 验证
5. 点击 "Submit" 发布

## 版本策略

### 语义化版本

- **主版本号**：不兼容的 API 修改
- **次版本号**：向下兼容的功能性新增
- **修订号**：向下兼容的问题修正

### 示例

| 版本 | 变更类型 |
|------|----------|
| 1.0.0 → 1.1.0 | 新增功能（向下兼容） |
| 1.1.0 → 1.1.1 | Bug 修复 |
| 1.1.1 → 2.0.0 | 不兼容的 API 修改 |

## 发布后工作

### 1. 更新文档

- [ ] 更新 README.md 中的版本信息
- [ ] 添加新功能示例
- [ ] 更新 API 文档

### 2. 通知用户

- [ ] 在 GitHub Releases 发布说明
- [ ] 在 Packagist 添加说明
- [ ] 在相关社区通知

### 3. 监控问题

- [ ] 监控 GitHub Issues
- [ ] 监控 Packagist 下载量
- [ ] 收集用户反馈

## 回滚流程

如果需要回滚：

```bash
# 1. 撤回标签
git push --delete origin v1.0.0

# 2. 撤回合并
gh pr revert <pr-number>

# 3. 从 Packagist 撤回
# 联系 Packagist 支持
```

## 常见问题

### Q: 如何发布预发布版本？

```bash
# 修改 composer.json
"version": "1.1.0-beta.1"

# 打标签
git tag v1.1.0-beta.1
git push origin v1.1.0-beta.1
```

### Q: 如何更新依赖版本？

```bash
# 更新依赖
composer update nikic/fast-route

# 运行测试
composer test

# 提交更改
git commit -am "chore: update fast-route to ^1.3"
```

### Q: 如何删除已发布的版本？

联系 Packagist 支持，提供：
- 包名
- 要删除的版本号
- 删除原因
