# Security Policy

## Supported Versions

Currently, the following versions are actively receiving security updates. We always recommend upgrading to the latest minor version (`^1.9.0`) with your Laravel installation to ensure optimal performance, structural compliance, and security.

| Version | Maintained?        | PHP Requirements | Laravel Framework |
| ------- | ------------------ | ---------------- | ----------------- |
| >= 1.9.0| :white_check_mark: | PHP 8.1+         | 8.x – 13.x        |
| >= 1.3.7| :warning:          | PHP 8.1+         | 8.x – 12.x        |
| < 1.3.7 | :x:                | -                | -                 |

## Reporting a Vulnerability

If you discover a security vulnerability within the **SmartCache** package, please send an e-mail to **Ismael Azaran** at **eazaran@gmail.com**. We assess all incoming reports within 48 hours to determine the scope and risk of the disclosure.

Please **do not** publicly disclose the issue on GitHub until we have had an opportunity to address it and publish a fix. When reporting a defect, please include:

- The Cache Driver and Database you are using (e.g. Redis, File, Memcached, MySQL)
- Whether the issue pertains to data leakage, cache poisoning, deserialization exploits, or denial of service
- Precise steps to reproduce the issue locally

Once verified, we will work closely with you on a patch and coordinate a security advisory release on GitHub to ensure all developers using the package can update seamlessly and safely.
