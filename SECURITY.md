# Security Policy

## Supported Versions

Only the latest SmartCache minor line receives routine security fixes. We recommend `^1.17` for existing 1.x installations.

| SmartCache Version | Maintained? | Package Compatibility |
| ------------------ | ----------- | --------------------- |
| 1.17.x             | :white_check_mark: | PHP 8.1–8.5, Laravel 8–13 |
| 1.9.x–1.16.x       | :warning: | Upgrade required; security backports are not guaranteed |
| < 1.9.0            | :x: | Unsupported |

SmartCache stores data only in the cache store you configure; it calls no external service and sends no telemetry. To harden deserialization, set Laravel's `cache.serializable_classes` and, since 1.17.0, `smart-cache.serializable_classes` (`'cache'` follows Laravel's setting), so the values SmartCache compresses, encrypts, or serializes itself are restricted too.

Package compatibility does not extend the security lifetime of PHP, Laravel, or the selected cache backend. Enterprise deployments should use a [currently supported PHP release](https://www.php.net/supported-versions.php) and a Laravel version inside the [official Laravel support window](https://laravel.com/docs/releases#support-policy).

## Reporting a Vulnerability

If you discover a security vulnerability within the **SmartCache** package, please send an e-mail to **Ismael Azaran** at **eazaran@gmail.com**. We assess all incoming reports within 48 hours to determine the scope and risk of the disclosure.

Please **do not** publicly disclose the issue on GitHub until we have had an opportunity to address it and publish a fix. When reporting a defect, please include:

- The Cache Driver and Database you are using (e.g. Redis, File, Memcached, MySQL)
- Whether the issue pertains to data leakage, cache poisoning, deserialization exploits, or denial of service
- Precise steps to reproduce the issue locally

Once verified, we will work closely with you on a patch and coordinate a security advisory release on GitHub to ensure all developers using the package can update seamlessly and safely.
