# Contributing to WP Vault

Thank you for your interest in contributing to WP Vault! We welcome contributions from the community.

## Code of Conduct

By participating in this project, you agree to maintain a respectful and inclusive environment for everyone.

## How Can I Contribute?

### Reporting Bugs

Before creating a bug report:

1. Check if the issue already exists
2. Verify it's not a configuration issue
3. Test with the latest version

When creating a bug report, include:

- WordPress version
- PHP version
- Plugin version
- Steps to reproduce
- Expected vs actual behavior
- Error messages or logs
- Screenshots (if applicable)

### Suggesting Features

We welcome feature suggestions! Please:

1. Check if the feature was already suggested
2. Explain the use case and benefits
3. Provide examples if possible

### Pull Requests

1. **Fork the repository**
2. **Create a feature branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. **Make your changes**
   - Follow WordPress coding standards
   - Write clear, commented code
   - Add tests if applicable
   - Update documentation
4. **Test thoroughly**
   - Test on multiple WordPress versions
   - Test with different PHP versions
   - Test edge cases
5. **Commit your changes**
   ```bash
   git commit -m "Add: Description of your changes"
   ```
6. **Push to your fork**
   ```bash
   git push origin feature/your-feature-name
   ```
7. **Create a Pull Request**
   - Provide a clear title and description
   - Reference related issues
   - Include screenshots for UI changes

## Development Setup

### Prerequisites

- WordPress 5.8+
- PHP 7.4+
- Composer (for dependencies)
- Git

### Setup Steps

1. Clone the repository

   ```bash
   git clone https://github.com/wpvault-cloud/wp-vault-plugin.git
   cd wp-vault-plugin
   ```

2. Install dependencies (if any)

   ```bash
   composer install
   ```

3. Set up a local WordPress environment

   - Use Local by Flywheel, XAMPP, or similar
   - Install WordPress
   - Symlink or copy plugin to `wp-content/plugins/wp-vault`

4. Activate the plugin in WordPress admin

## Coding Standards

### PHP Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- Use WordPress functions and hooks
- Prefix all functions with `wp_vault_` or use namespaces
- Use meaningful variable and function names
- Add PHPDoc comments for all functions and classes

### JavaScript Standards

- Follow WordPress JavaScript Coding Standards
- Use ES6+ features
- Comment complex logic
- Use WordPress jQuery when needed

### CSS Standards

- Follow WordPress CSS Coding Standards
- Use BEM methodology
- Keep specificity low
- Use WordPress admin color scheme variables

## Commit Messages

Use clear, descriptive commit messages:

```
Add: Feature description
Fix: Bug description
Update: Change description
Remove: Removal description
Refactor: Refactoring description
```

Examples:

- `Add: Support for Backblaze B2 storage`
- `Fix: Backup timeout issue with large files`
- `Update: Improve error handling in restore process`

## Testing

Before submitting a PR:

- Test on WordPress 5.8, 6.0, and latest version
- Test with PHP 7.4, 8.0, and 8.1
- Test backup and restore functionality
- Test with different storage providers
- Test error scenarios

## Documentation

- Update README.md if adding features
- Add code comments for complex logic
- Update inline documentation
- Add examples if applicable

## License

By contributing, you agree that your contributions will be licensed under the same GPLv2 or later license as the project. See [LICENSE](LICENSE) for details.

## Questions?

- Open an issue for discussion
- Email: dev@wpvault.cloud
- Check existing documentation

Thank you for contributing to WP Vault! 🎉
