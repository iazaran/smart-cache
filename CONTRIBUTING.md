# Contributing

I love your input! I want to make contributing to this project as easy and transparent as possible, whether it's:

- Reporting a bug
- Discussing the current state of the code
- Submitting a fix
- Proposing new features
- Becoming a maintainer

## Security Vulnerabilities

If you discover a security vulnerability, please review our [Security Policy](SECURITY.md) for responsible disclosure instructions. **Do not** open a public GitHub issue for security concerns.

## All Code Changes Happen Through Pull Requests

Pull requests are the best way to propose changes to the codebase. I actively welcome your pull requests:

1. Fork the repo and create your branch from `main`.
2. If you've added code that should be tested, add tests.
3. If you've changed APIs or functions, update the documentation.
4. Ensure your code follows the **PSR-12** coding standard.
5. Make sure your code lints and all tests pass.
6. Document notable changes in your PR description for the [CHANGELOG](CHANGELOG.md).
7. Issue that pull request!

## Running Tests

```bash
# Run the full test suite
composer test

# Run with code coverage
composer test-coverage
```

All pull requests must pass the existing test suite (425+ tests) before merging. If you add a new feature, include corresponding test cases.

## Coding Style

This project follows the **PSR-12** coding standard. Key points:

- Use 4 spaces for indentation (enforced by `.editorconfig`)
- Opening braces on the same line for control structures
- Opening braces on the next line for classes and methods
- One `use` declaration per line
- Type declarations and return types where applicable

## Any contributions you make will be under the MIT Software License

In short, when you submit code changes, your submissions are understood to be under the same.

## License

By contributing, you agree that your contributions will be licensed under its MIT License.