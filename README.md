# XBoard Alipay PC Website Payment Plugin

An Alipay PC website payment plugin for XBoard. It integrates `alipay.trade.page.pay` with RSA2 request signing, auto-submitted payment forms, asynchronous notification verification, and order amount validation.

## Installation

Package this directory as a ZIP file, then upload, install, and enable it from the XBoard plugin manager. The plugin code is `alipay_pagepay`, and the installed directory should be `plugins/AlipayPagepay`.

## Configuration

- Alipay application APPID
- PKCS#1 application private key for PHP
- Alipay public key (not the application public key)
- Alipay merchant PID
- Production or sandbox environment
- Optional product name and payment timeout

The asynchronous notification URL shown in the XBoard admin panel must be publicly reachable over HTTPS and must not redirect. The synchronous return only brings the user back to the order page; payment success must be determined from a verified asynchronous notification or a server-side query.

## Security

Keep private keys only in the server-side payment configuration. Never place them in logs, client-side code, or public repositories. Before production use, test the complete payment flow, asynchronous notifications, and order amount validation with real merchant credentials.
