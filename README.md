# CascadeDocs

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lumiio/cascadedocs.svg?style=flat-square)](https://packagist.org/packages/lumiio/cascadedocs)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/lumiio/cascadedocs/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/lumiio/cascadedocs/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/lumiio/cascadedocs/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/lumiio/cascadedocs/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/lumiio/cascadedocs.svg?style=flat-square)](https://packagist.org/packages/lumiio/cascadedocs)

Automatically generate and maintain comprehensive documentation for your Laravel codebase using AI. CascadeDocs analyzes your code, organizes it into logical modules, and creates multi-tier documentation that stays in sync with your development.

## Why CascadeDocs?

Modern codebases grow complex quickly. New team members struggle to understand the system architecture. Documentation becomes outdated the moment it's written. CascadeDocs solves these problems by:

- **Understanding Your Code**: AI analyzes your actual code structure, not just comments
- **Multiple Perspectives**: Three documentation tiers serve different needs:
  - **Micro**: Quick overview for code reviews and navigation
  - **Standard**: Balanced documentation for everyday development
  - **Expansive**: Deep dive for complex debugging and onboarding
- **Living Documentation**: Git integration ensures docs update only when code actually changes
- **Contextual Understanding**: Modules show how files work together, not just what individual files do

### Perfect for AI-Powered Development

When using coding assistants like Claude Code, Cursor, or GitHub Copilot, context is everything. CascadeDocs provides:

- **Module Index**: Feed the `modules/index.md` to your AI to instantly convey your entire system architecture
- **Targeted Context**: Share specific module documentation to keep AI responses focused and accurate
- **Consistent Code Generation**: AI understands your patterns and conventions from the documented modules
- **Better Recommendations**: With full system context, AI agents make architectural decisions that fit your existing design

Instead of copying random files and hoping for the best, you can give your AI assistant exactly the context it needs to understand where new features should go and how they should be implemented.

## Key Features

- 🤖 **AI-Powered Analysis**: Uses OpenAI or Claude to understand your code and generate meaningful documentation
- 📚 **Three Documentation Tiers**: Choose between micro (brief), standard (balanced), or expansive (detailed) documentation
- 🗂️ **Automatic Module Organization**: Intelligently groups related files into cohesive modules
- 🔄 **Git-Based Updates**: Only regenerates documentation for files that have actually changed
- 🏗️ **Architecture Overview**: Automatically generates high-level system architecture documentation
- ⚡ **Queue Support**: Process large codebases asynchronously without blocking your application
- 📊 **Module Index**: Provides a comprehensive overview of all modules for easy navigation

## Quick Start

### Installation

```bash
composer require lumiio/cascadedocs
```

### Setup

1. **Publish the AI provider configuration:**
```bash
php artisan vendor:publish --provider="Shawnveltman\LaravelOpenai\LaravelOpenaiServiceProvider"
```

2. **Add your AI credentials to `.env`:**
```bash
# For OpenAI
OPENAI_API_KEY=your-openai-api-key
OPENAI_ORGANIZATION=your-org-id # Optional

# For Claude/Anthropic
ANTHROPIC_API_KEY=your-anthropic-api-key
```

3. **Publish CascadeDocs configuration:**
```bash
php artisan vendor:publish --tag="cascadedocs-config"
```

### Generate Documentation

Run these commands in order for a complete documentation setup:

```bash
# 1. Generate documentation for all your classes
php artisan cascadedocs:generate-class-docs

# 2. Organize files into logical modules and create module documentation
php artisan cascadedocs:generate-module-docs

# 3. Generate high-level architecture documentation
php artisan cascadedocs:generate-architecture-docs
```

**Important**: If you're using queue processing, wait for each command's jobs to complete before running the next command. Monitor your queue with `php artisan queue:work` or check your queue dashboard.

Your documentation is now available in `docs/source_documents/`.

## Documentation Workflow

### Initial Setup

When starting with a new codebase:

1. **Generate class documentation** - Creates documentation for each file at three detail levels
2. **Generate module documentation** - Groups related files and creates module overviews
3. **Generate architecture documentation** - Creates system-level documentation from module summaries

### Keeping Documentation Updated

As your code evolves:

```bash
# Update documentation for changed files only
php artisan cascadedocs:update-changed

# Update and auto-commit the changes
php artisan cascadedocs:update-changed --auto-commit
```

The update command uses Git to detect which files have changed since the last documentation run, saving time and API costs.

## Documentation Structure

```
docs/source_documents/
├── short/           # Micro-tier: Brief one-line summaries
├── medium/          # Standard-tier: Balanced documentation
├── full/            # Expansive-tier: Detailed documentation with examples
├── modules/         # Module-level documentation
│   ├── content/     # Module overview documents
│   ├── metadata/    # Module configuration and tracking
│   └── index.md     # Searchable index of all modules
└── architecture/    # System-level architecture documentation
    ├── architecture-summary.md    # 1-2 page overview
    └── system-architecture.md     # Comprehensive architecture doc
```

## Command Reference

### Class Documentation

```bash
# Generate all tiers (default)
php artisan cascadedocs:generate-class-docs

# Generate specific tier only
php artisan cascadedocs:generate-class-docs --tier=standard

# Force regeneration of existing docs
php artisan cascadedocs:generate-class-docs --force

# Use a specific AI model
php artisan cascadedocs:generate-class-docs --model=gpt-4
```

### Module Documentation

#### Understanding the Module Workflow

CascadeDocs uses modules to group related files together. The module system has two distinct phases:

1. **Module Assignment** - Deciding which files belong to which modules
2. **Documentation Generation** - Creating documentation for those modules

**Important**: The `update-all-modules` command only updates documentation for existing modules with already-assigned files. It does NOT create new modules or assign unassigned files.

#### Complete Module Workflow

```bash
# Recommended: Run the complete workflow
php artisan cascadedocs:generate-module-docs
```

This command orchestrates the entire process:
1. Analyzes module structure (skipped if already done)
2. Assigns unassigned files to modules
3. Syncs module assignments
4. Updates all module documentation
5. Shows final module status

#### Manual Module Management

For more control over module creation:

```bash
# 1. Check current module status and unassigned files
php artisan cascadedocs:module-status

# 2. Get AI suggestions for unassigned files (preview only)
php artisan cascadedocs:assign-files-to-modules --dry-run

# 3. Review and apply suggestions
php artisan cascadedocs:assign-files-to-modules
# You'll be shown the suggestions and asked:
#   - "Yes": Apply all changes (assigns files AND creates new modules)
#   - "No": Cancel without changes
#   - "Feedback": Provide feedback for better suggestions

# 4. Generate documentation for the modules
php artisan cascadedocs:update-all-modules

# Optional: Skip confirmation prompts
php artisan cascadedocs:assign-files-to-modules --force
```

#### Interactive Assignment

For maximum control:

```bash
# Review each suggestion individually
php artisan cascadedocs:assign-files-to-modules --interactive

# Process only a few files at a time
php artisan cascadedocs:assign-files-to-modules --limit=5
```

#### Other Module Commands

```bash
# View detailed module analysis
php artisan cascadedocs:analyze-modules --suggest

# Create a module manually
php artisan cascadedocs:create-module

# Force fresh analysis (ignores cache)
php artisan cascadedocs:generate-module-docs --fresh

# Generate module index
php artisan cascadedocs:generate-module-index
```

### Update Documentation

```bash
# Update based on git changes
php artisan cascadedocs:update-changed

# Update from specific commit
php artisan cascadedocs:update-changed --from-sha=abc123

# Update and commit changes
php artisan cascadedocs:update-changed --auto-commit
```

### Utility Commands

```bash
# View module status
php artisan cascadedocs:module-status

# Create a new module manually
php artisan cascadedocs:create-module "Payment Processing"

# Force fresh module analysis (ignores cache)
php artisan cascadedocs:generate-module-docs --fresh
```

## Configuration

The `config/cascadedocs.php` file controls:

```php
return [
    'paths' => [
        'source' => ['app/', 'resources/js/'],  // Directories to document
        'output' => 'docs/source_documents/',    // Documentation location
    ],
    'file_types' => ['php', 'js', 'vue', 'jsx', 'ts', 'tsx'],
    'ai' => [
        'default_model' => 'gpt-4',  // or 'claude-3-5-sonnet', etc.
        'temperature' => 0.7,
    ],
    'queue' => [
        'connection' => 'default',
        'queue' => 'documentation',
    ],
];
```

## Tracking Files

CascadeDocs maintains several files to track state and enable efficient updates:

### `docs/module-assignment-log.json`
Caches the AI's module organization analysis to maintain consistency across runs. This includes which files are assigned to which modules and any unassigned files.

### `docs/source_documents/modules/metadata/*.json`
Individual module metadata including file assignments, summaries, and documentation status.

### Documentation Files (YAML Frontmatter)
Each documentation file in `docs/source_documents/full/` contains YAML frontmatter with:
- `source_path`: The original source file path
- `commit_sha`: The Git SHA when the documentation was generated
- `doc_tier`: The documentation tier level

This embedded metadata enables CascadeDocs to detect when files have changed since their documentation was last generated.

These files should be committed to your repository to maintain state across team members.

## Programmatic Usage

```php
use Lumiio\CascadeDocs\Facades\CascadeDocs;

// Get documentation for a specific file
$docs = CascadeDocs::getDocumentation('app/Models/User.php', 'standard');

// Get all modules
$modules = CascadeDocs::getModules();

// Get module documentation
$moduleDocs = CascadeDocs::getModuleDocumentation('user-management');

// Get file's module assignment
$module = CascadeDocs::getFileModule('app/Services/UserService.php');
```

## Best Practices

1. **Start with class documentation** - This provides the foundation for module organization
2. **Review module assignments** - The AI makes intelligent suggestions, but you can manually adjust
3. **Use queues for large codebases** - Prevents timeouts and improves performance
4. **Commit tracking files** - Ensures consistent documentation across your team
5. **Run updates regularly** - Keep documentation in sync with code changes

## Troubleshooting

### "No modules found"
Run `php artisan cascadedocs:generate-class-docs` first to create the base documentation.

### "No modules have undocumented files" but I have unassigned files
This is a common confusion. The `update-all-modules` command only updates documentation for files that are already assigned to modules. To handle unassigned files:

1. Run `php artisan cascadedocs:module-status` to see unassigned files
2. Run `php artisan cascadedocs:assign-files-to-modules --auto-create` to assign them to modules
3. THEN run `php artisan cascadedocs:update-all-modules` to generate their documentation

Remember: Module assignment and documentation generation are separate steps!

### "AI response contains placeholder text"
The AI model is returning incomplete responses. Try using a different model (e.g., GPT-4 instead of GPT-3.5, or Claude instead of OpenAI).

### Rate limit errors
- Ensure your queue worker is running: `php artisan queue:work`
- The package automatically retries with delays

### Memory issues
- Use queue processing for large codebases
- Process documentation generation in smaller batches by documenting specific directories

## Requirements

- PHP 8.2+
- Laravel 10.0+
- Git (for change tracking features)

## Testing

```bash
composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Credits

- [Shawn Veltman](https://github.com/shawnveltman)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.