# 贡献指南

感谢你对 `kode/router` 的兴趣！我们欢迎各种形式的贡献。

## 如何贡献

### 报告 Bug

1. 检查是否已有类似问题
2. 创建新的 Issue，包含：
   - 问题描述
   - 复现步骤
   - 期望行为
   - 实际行为
   - 环境信息（PHP 版本、操作系统等）

### 提交代码

1. Fork 本仓库
2. 创建特性分支：`git checkout -b feature/your-feature`
3. 提交更改：`git commit -am 'feat: Add some feature'`
4. 推送分支：`git push origin feature/your-feature`
5. 创建 Pull Request

### 代码规范

请遵循 [CODE_STYLE.md](CODE_STYLE.md) 中的规范：
- PSR-12 编码风格
- PHP 8.3+ 特性
- 完整的中文注释
- 类型声明

### 测试

确保所有测试通过：
```bash
composer install
vendor/bin/phpunit
```

测试覆盖率目标：80%+

## 开发环境

### 前置要求

- PHP 8.3+
- Composer
- Git

### 安装开发依赖

```bash
composer install
```

### 运行测试

```bash
# 运行所有测试
vendor/bin/phpunit

# 运行特定测试文件
vendor/bin/phpunit tests/RouterTest.php

# 运行特定测试方法
vendor/bin/phpunit --filter testBasicRoute

# 生成覆盖率报告
vendor/bin/phpunit --coverage-html=coverage
```

## 提交信息规范

使用 [Conventional Commits](https://www.conventionalcommits.org/) 格式：

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Type 类型

- `feat`: 新功能
- `fix`: 修复 bug
- `docs`: 文档变更
- `style`: 代码格式（不影响功能）
- `refactor`: 重构
- `test`: 测试相关
- `chore`: 构建/工具相关

### 示例

```
feat(router): 添加路由组嵌套支持

- 支持 RouteGroup 嵌套
- 更新测试用例

Closes #123
```

## 行为准则

### 我们的承诺

为了营造开放和友好的环境，我们承诺：

- 使用友好和包容的语言
- 尊重不同的观点和经验
- 虚心接受建设性批评
- 关注对社区最有利的事情
- 对其他社区成员表示体贴

### 不可接受的行为

- 使用性化的语言或图像
- 人身攻击或侮辱性评论
- 公开或私下骚扰
- 未经许可发布他人信息
- 其他不恰当的行为

## 许可证

通过贡献代码，你同意你的贡献将在 MIT 许可证下授权。

## 问题解答

- 阅读 [README.md](README.md)
- 查看 [文档](docs/)
- 在 Issue 中提问

## 感谢

感谢所有为本项目做出贡献的人！

---

有问题？请联系：[zhuanz@example.com](mailto:zhuanz@example.com)
