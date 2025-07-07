# CascadeDocs

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lumiio/cascadedocs.svg?style=flat-square)](https://packagist.org/packages/lumiio/cascadedocs)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/lumiio/cascadedocs/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/lumiio/cascadedocs/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/lumiio/cascadedocs/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/lumiio/cascadedocs/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/lumiio/cascadedocs.svg?style=flat-square)](https://packagist.org/packages/lumiio/cascadedocs)

AI-powered documentation generation for Laravel applications. CascadeDocs automatically generates comprehensive documentation for your codebase using AI, organizing it into classes, modules, and architecture levels.

## Features

- 🤖 **AI-Powered Documentation**: Uses OpenAI/Claude to generate intelligent documentation
- 📚 **Multi-Tier Documentation**: Generate micro, standard, or expansive documentation levels
- 🗂️ **Smart Module Organization**: Automatically groups related files into logical modules
- 🏗️ **Architecture Documentation**: Creates high-level system architecture documentation
- 🔄 **Incremental Updates**: Only regenerates documentation for changed files
- 🎯 **Git Integration**: Track changes and update documentation based on commits
- ⚡ **Queue Support**: Process documentation generation asynchronously
- 🛠️ **Highly Configurable**: Customize paths, file types, and AI settings

## Requirements

- PHP 8.2+
- Laravel 10.0+
- Git (for change tracking features)

## Installation

You can install the package via composer:

```bash
composer require lumiio/cascadedocs
```

### Required: Install AI Provider Package

CascadeDocs uses the `shawnveltman/laravel-openai` package for AI integration. You must publish its configuration:

```bash
php artisan vendor:publish --provider="Shawnveltman\LaravelOpenai\LaravelOpenaiServiceProvider"
```

This will create a `config/openai.php` file. Configure your AI provider credentials in your `.env` file:

```bash
# For OpenAI
OPENAI_API_KEY=your-openai-api-key
OPENAI_ORGANIZATION=your-org-id # Optional

# For Claude/Anthropic
ANTHROPIC_API_KEY=your-anthropic-api-key
```

### Publish CascadeDocs Configuration

```bash
php artisan vendor:publish --tag="cascadedocs-config"
```

This will create a `config/cascadedocs.php` file where you can customize settings.

## Usage

### Quick Start

Generate documentation for your entire codebase:

```bash
# Generate class documentation
php artisan cascadedocs:generate-class-docs

# Organize into modules
php artisan cascadedocs:generate-module-docs

# Generate architecture overview
php artisan cascadedocs:generate-architecture-docs
```

### Update Documentation

Keep your documentation in sync with code changes:

```bash
# Update based on git changes
php artisan cascadedocs:update-changed

# Auto-commit documentation updates
php artisan cascadedocs:update-changed --auto-commit
```

### Programmatic Usage

```php
use Lumiio\CascadeDocs\Facades\CascadeDocs;

// Get documentation for a file
$docs = CascadeDocs::getDocumentation('app/Models/User.php', 'standard');

// Get all modules
$modules = CascadeDocs::getModules();

// Get module documentation
$moduleDocs = CascadeDocs::getModuleDocumentation('user-management');
```

## Configuration

The configuration file allows you to customize:

```php
return [
    'paths' => [
        'source' => ['app/', 'resources/js/'],  // Directories to document
        'output' => 'docs/source_documents/',    // Where to store documentation
    ],
    'file_types' => ['php', 'js', 'vue', 'jsx', 'ts', 'tsx'],
    'ai' => [
        'default_model' => 'gpt-4',  // or 'o3', 'claude-3', etc.
        'temperature' => 0.7,
    ],
    'exclude' => [
        'directories' => ['vendor', 'node_modules'],
        'patterns' => ['*Test.php', '*.min.js'],
    ],
];
```

## Commands Reference

### Generate Class Documentation

```bash
# Generate all tiers
php artisan cascadedocs:generate-class-docs

# Generate specific tier (micro, standard, expansive)
php artisan cascadedocs:generate-class-docs --tier=standard

# Force regeneration
php artisan cascadedocs:generate-class-docs --force

# Use specific AI model
php artisan cascadedocs:generate-class-docs --model=gpt-4
```

### Generate Module Documentation

```bash
# Full module generation workflow
php artisan cascadedocs:generate-module-docs

# Individual module commands
php artisan documentation:module:create "User Management"
php artisan documentation:module:assign --force
php artisan documentation:module:update-all
php artisan documentation:module:status
```

### Generate Architecture Documentation

```bash
php artisan cascadedocs:generate-architecture-docs
```

### Update Documentation for Changes

```bash
# Update from last documented commit
php artisan cascadedocs:update-changed

# Update from specific commit
php artisan cascadedocs:update-changed --from-sha=abc123 --to-sha=HEAD

# Auto-commit documentation changes
php artisan cascadedocs:update-changed --auto-commit
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Documentation Structure

CascadeDocs generates documentation in the following structure:

```
docs/source_documents/
├── short/          # Micro-level documentation (brief summaries)
├── medium/         # Standard documentation
├── full/           # Expansive documentation with full details
├── modules/        # Module organization
│   ├── content/    # Module documentation files
│   └── metadata/   # Module configuration and metadata
└── architecture/   # System-level architecture documentation
```

## How It Works

1. **File Analysis**: CascadeDocs scans your configured source directories for code files
2. **AI Generation**: Each file is processed by AI to generate documentation at multiple detail levels
3. **Module Organization**: Files are intelligently grouped into logical modules based on their purpose and relationships
4. **Architecture Synthesis**: Module summaries are analyzed to create high-level architecture documentation
5. **Change Tracking**: Git integration tracks changes and only updates affected documentation

## Troubleshooting

### AI Provider Setup

Make sure you have:

1. Published the AI provider configuration:
   ```bash
   php artisan vendor:publish --provider="Shawnveltman\LaravelOpenai\LaravelOpenaiServiceProvider"
   ```

2. Configured your AI provider credentials in `.env`:
   ```bash
   # For OpenAI
   OPENAI_API_KEY=your-api-key
   OPENAI_ORGANIZATION=your-org-id # Optional
   
   # For Claude/Anthropic
   ANTHROPIC_API_KEY=your-api-key
   ```

### Queue Configuration

For large codebases, ensure your queue worker is running:

```bash
php artisan queue:work
```

### Common Issues

- **"No modules found"**: Run `php artisan cascadedocs:generate-class-docs` first
- **"AI response contains placeholder text"**: The AI model is not providing complete responses. Try using a different model
- **Memory issues**: Adjust the `chunk_size` in the configuration to process fewer files at once

## Credits

- [Shawn Veltman](https://github.com/shawnveltman)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
