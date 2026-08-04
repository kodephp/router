# PHPStan 配置说明

## 配置说明

```neon
parameters:
    level: 8  # 静态分析严格级别（0-8，8 为最高）
    
paths:
    - src     # 分析 src 目录下的代码
```

## 运行方式

```bash
# 运行 PHPStan
vendor/bin/phpstan analyse

# 指定配置
vendor/bin/phpstan analyse -c phpstan.neon

# 持续监控模式
vendor/bin/phpstan analyse --watch
```

## 参数说明

| 参数 | 说明 |
|------|------|
| `level` | 分析严格级别（0-8） |
| `paths` | 要分析的文件/目录 |
| `excludePaths` | 排除的文件/目录 |
| `reportUnmatchedIgnoredErrors` | 是否报告未匹配的 ignoreErrors |

## 常见配置

### 排除文件
```neon
parameters:
    excludePaths:
        - tests/*
        - example/*
```

### 忽略特定错误
```neon
parameters:
    ignoreErrors:
        - '#^Method .* has no return type specified.$#'
```

### 自定义规则
```neon
parameters:
    customRules:
        - %currentWorkingDirectory%/src/CustomRule.php
```
