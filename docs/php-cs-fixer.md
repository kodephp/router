# PHP-CS-Fixer 配置说明

## 配置说明

```php
<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
    ])
    ->setFinder($finder);
```

## 运行方式

```bash
# 修复代码风格
vendor/bin/php-cs-fixer fix

# 检查但不修复
vendor/bin/php-cs-fixer fix --dry-run

# 检查并显示差异
vendor/bin/php-cs-fixer fix --dry-run --diff
```

## 常用规则

| 规则 | 说明 |
|------|------|
| `@PSR12` | 启用所有 PSR-12 规则 |
| `array_syntax` | 数组语法（short 或 long） |
| `no_unused_imports` | 移除未使用的 import |
| `ordered_imports` | 排序 import 语句 |
| `single_quote` | 使用单引号 |

## 配置文件

可以使用 `.php-cs-fixer.dist.php` 或 `.php-cs-fixer.php` 作为配置文件名。
