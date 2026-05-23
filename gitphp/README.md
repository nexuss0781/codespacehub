# CodespaceHub — Enterprise-Grade Self-Hosted Code Repository Platform

<div align="center">

![GitPHP](https://img.shields.io/badge/GitPHP-v1.0.0-2dd4bf?style=for-the-badge&logo=github)
![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=for-the-badge&logo=php)
![SQLite](https://img.shields.io/badge/SQLite-WAL-003B57?style=for-the-badge&logo=sqlite)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

**A full-featured, GitHub-inspired code hosting platform built with pure PHP — zero frameworks, zero Composer dependencies.**

[Features](#-features) • [Quick Start](#-quick-start) • [Installation](#-installation) • [Configuration](#-configuration) • [API Reference](#-api-reference) • [Security](#-security)

</div>

---

## 📖 Overview

CodespaceHub (GitPHP) is a sophisticated, self-hosted repository management system that delivers a modern GitHub-like experience without the complexity. Engineered from the ground up with vanilla PHP, it provides a lightweight yet powerful alternative for teams and individuals seeking complete control over their code infrastructure.

Built on a foundation of **SQLite with WAL mode**, **file-based caching**, and a **clean MVC-inspired architecture**, CodespaceHub offers enterprise-grade features in a single, deployable package.

---

## ✨ Key Features

### 🔐 Authentication & User Management
- **Secure Registration/Login** — Bcrypt password hashing with session-based authentication
- **User Profiles** — Customizable profiles with avatars, bios, and public repository showcases
- **Role-Based Access** — Owner permissions for repository management

### 📦 Repository Management
- **Public & Private Repositories** — Granular visibility controls
- **One-Click Creation** — Instant repository initialization with default README
- **ZIP Upload System** — Drag-and-drop project uploads (up to 100MB)
- **Smart .gitignore** — Automatic exclusion of build artifacts, dependencies, and sensitive files
- **Repository Statistics** — Real-time language composition analysis with visual breakdowns

### 📝 Code Editor & File Browser
- **In-Browser IDE** — Full-featured code editor with syntax highlighting for 30+ languages
- **Tab-Support Navigation** — Efficient keyboard shortcuts (Ctrl+S to save)
- **Intelligent File Browser** — Directory-first sorting with recursive navigation
- **File Operations** — Create, edit, delete files and directories directly from the UI
- **Raw File Access** — Direct file serving for scripts and assets

### 📄 Markdown Rendering Engine
- **Full Markdown Support** — Headers, lists, tables, blockquotes, task lists
- **Syntax Highlighting** — Language-aware code blocks with copy functionality
- **Image Embedding** — Inline images with responsive styling
- **Table Rendering** — Complex table structures with proper alignment
- **Caching Layer** — Pre-rendered Markdown cached for optimal performance

### ⭐ Social Features
- **Star System** — Bookmark and showcase favorite repositories
- **Search Functionality** — Full-text search across repository names, descriptions, and usernames
- **Activity Tracking** — Last-updated timestamps and contribution history

### 🎨 Modern UI/UX
- **Dark Theme First** — Carefully crafted color palette (`#2dd4bf` brand accent)
- **Responsive Design** — Mobile-optimized layouts with adaptive components
- **Typography** — DM Sans for UI, JetBrains Mono for code
- **Icon System** — Lucide icons for consistent visual language
- **Toast Notifications** — Real-time feedback for user actions
- **Modal Dialogs** — Smooth animations for forms and confirmations

### ⚡ Performance Optimizations
- **SQLite WAL Mode** — Write-Ahead Logging for concurrent read optimization
- **Query Caching** — 60-second TTL cache for expensive operations
- **Gzip Compression** — Automatic response compression
- **HTTP Caching Headers** — Browser-level caching for static assets
- **Minimal Dependencies** — Only Tailwind CDN, Lucide CDN, Google Fonts

---

## 🚀 Quick Start

### Local Development Server

```bash
# Navigate to the gitphp directory
cd gitphp

# Start PHP's built-in development server
php -S localhost:8080 router.php

# Open your browser
open http://localhost:8080
```

That's it! The application will automatically create the SQLite database (`gitphp.db`) and necessary directories on first run.

---

## 📋 Requirements

| Component | Version | Required Extensions |
|-----------|---------|---------------------|
| **PHP** | 8.1+ | `pdo_sqlite`, `zip`, `fileinfo` |
| **Web Server** | Apache 2.4+ / Nginx 1.18+ / PHP Built-in | `mod_rewrite` (Apache) |
| **Database** | SQLite 3.35+ | WAL mode support |

### Extension Verification

```bash
php -m | grep -E "(pdo_sqlite|zip|fileinfo)"
```

---

## 🛠️ Installation

### Option 1: Apache Deployment

```bash
# Copy files to web root
sudo cp -r gitphp/ /var/www/html/gitphp/
cd /var/www/html/gitphp

# Set permissions
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod 777 repos/ uploads/ cache/

# Enable mod_rewrite
sudo a2enmod rewrite
sudo systemctl restart apache2

# Verify Apache config has AllowOverride All
sudo nano /etc/apache2/sites-available/000-default.conf
# Ensure: <Directory /var/www/html> AllowOverride All </Directory>
```

### Option 2: Nginx Deployment

```bash
# Copy configuration
sudo cp nginx.conf /etc/nginx/sites-available/gitphp

# Edit root path in config
sudo nano /etc/nginx/sites-available/gitphp
# Change: root /var/www/gitphp;

# Enable site
sudo ln -s /etc/nginx/sites-available/gitphp /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx

# Set permissions
sudo chown -R www-data:www-data /var/www/gitphp
sudo chmod -R 755 /var/www/gitphp
sudo chmod 777 /var/www/gitphp/{repos,uploads,cache}
```

### Option 3: Docker (Recommended for Production)

```dockerfile
FROM php:8.2-apache

RUN docker-php-ext-install pdo_sqlite zip fileinfo
RUN a2enmod rewrite headers deflate

COPY gitphp/ /var/www/html/
WORKDIR /var/www/html

RUN chown -R www-data:www-data . \
    && chmod -R 755 . \
    && chmod 777 repos uploads cache

EXPOSE 80
```

---

## 📁 Project Structure

```
gitphp/
├── index.php              # Application entry point & view renderer
├── router.php             # Development server URL router
├── .htaccess              # Apache rewrite rules & security headers
├── nginx.conf             # Nginx server configuration template
├── gitphp.db              # SQLite database (auto-generated)
│
├── includes/
│   ├── config.php         # Core configuration, DB connection, utilities
│   ├── controllers.php    # Request handlers (auth, repos, API endpoints)
│   ├── repofs.php         # Repository filesystem abstraction layer
│   └── markdown.php       # Markdown parsing & HTML rendering engine
│
├── repos/                 # User repositories storage
│   └── {username}/
│       └── {reponame}/    # Individual repository contents
│
├── uploads/               # Temporary ZIP upload staging area
│   └── *.zip              # Auto-cleaned after extraction
│
├── cache/                 # File-based cache storage
│   ├── md_*.html          # Pre-rendered Markdown
│   └── *.cache            # Query result caches
│
└── LICENSE                # MIT License
```

---

## 🔧 Configuration

### Environment Constants (`includes/config.php`)

| Constant | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `'GitPHP'` | Application display name |
| `APP_VERSION` | `'1.0.0'` | Current version |
| `BASE_PATH` | `dirname(__DIR__)` | Root directory path |
| `REPOS_PATH` | `BASE_PATH . '/repos'` | Repository storage location |
| `UPLOADS_PATH` | `BASE_PATH . '/uploads'` | Temporary upload directory |
| `CACHE_PATH` | `BASE_PATH . '/cache'` | Cache file directory |
| `MAX_FILE_SIZE` | `104857600` (100MB) | Maximum upload size in bytes |
| `DB_PATH` | `BASE_PATH . '/gitphp.db'` | SQLite database file path |

### Ignored Patterns (Auto-.gitignore)

The following patterns are automatically excluded during ZIP extraction:

| Category | Patterns |
|----------|----------|
| **Build Outputs** | `build/`, `dist/`, `.next/`, `.nuxt/`, `out/`, `coverage/` |
| **Dependencies** | `node_modules/`, `vendor/` |
| **Version Control** | `.git/`, `.svn/` |
| **IDE Configs** | `.idea/`, `.vscode/`, `*.swp` |
| **Environment** | `.env`, `*.log`, `*.tmp` |
| **System Files** | `.DS_Store`, `Thumbs.db`, `__pycache__/` |

---

## 🌐 API Reference

### Authentication Endpoints

#### POST `/api/register`
Register a new user account.

**Request Body:**
```json
{
  "username": "string (3-30 chars, alphanumeric)",
  "email": "string (valid email)",
  "password": "string (min 6 chars)"
}
```

**Response:**
```json
{"success": true}
// or
{"error": "Username or email already taken"}
```

#### POST `/api/login`
Authenticate user credentials.

**Request Body:**
```json
{
  "username": "string (username or email)",
  "password": "string"
}
```

**Response:**
```json
{"success": true}
// or
{"error": "Invalid credentials"}
```

#### POST `/api/logout`
Destroy user session.

**Response:**
```json
{"success": true}
```

---

### Repository Endpoints

#### POST `/api/create-repo`
Create a new repository.

**Headers:** `Authentication required (session)`

**Request Body:**
```json
{
  "name": "string (alphanumeric, -, _, .)",
  "description": "string (optional)",
  "is_private": "boolean (optional, default: false)"
}
```

**Response:**
```json
{"success": true, "redirect": "/username/reponame"}
```

#### POST `/api/upload/:username/:reponame`
Upload a ZIP file for extraction into a repository.

**Headers:** `Content-Type: multipart/form-data`, `Authentication required`

**Form Data:**
- `zipfile`: ZIP archive (max 100MB)

**Response:**
```json
{
  "success": true,
  "extracted": 42,
  "skipped": 5
}
```

#### POST `/api/save-file/:username/:reponame`
Save or update a file in a repository.

**Headers:** `Authentication required`

**Request Body:**
```json
{
  "file_path": "path/to/file.ext",
  "content": "string"
}
```

**Response:**
```json
{"success": true}
```

#### POST `/api/delete-file/:username/:reponame`
Delete a file or directory from a repository.

**Headers:** `Authentication required`

**Request Body:**
```json
{
  "file_path": "path/to/file.ext"
}
```

**Response:**
```json
{"success": true}
```

#### POST `/api/star/:repoId`
Toggle star status on a repository.

**Headers:** `Authentication required`

**Response:**
```json
{"starred": true}
// or
{"starred": false}
```

---

### Content Endpoints

#### GET `/api/raw/:username/:reponame/:path...`
Retrieve raw file content.

**Supported MIME Types:**
- `text/plain`, `application/javascript`, `text/css`
- `text/html`, `application/json`, `image/svg+xml`

**Response:** Raw file content with appropriate `Content-Type` header.

---

## 🗄️ Database Schema

### Tables

#### `users`
| Column | Type | Constraints |
|--------|------|-------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `username` | TEXT | UNIQUE NOT NULL |
| `email` | TEXT | UNIQUE NOT NULL |
| `password` | TEXT | NOT NULL (bcrypt hash) |
| `avatar` | TEXT | DEFAULT '' |
| `bio` | TEXT | DEFAULT '' |
| `created_at` | INTEGER | Unix timestamp |

#### `repositories`
| Column | Type | Constraints |
|--------|------|-------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `user_id` | INTEGER | FOREIGN KEY → users(id) |
| `name` | TEXT | NOT NULL |
| `description` | TEXT | DEFAULT '' |
| `is_private` | INTEGER | DEFAULT 0 |
| `default_branch` | TEXT | DEFAULT 'main' |
| `stars` | INTEGER | DEFAULT 0 |
| `forks` | INTEGER | DEFAULT 0 |
| `created_at` | INTEGER | Unix timestamp |
| `updated_at` | INTEGER | Unix timestamp |

#### `stars`
| Column | Type | Constraints |
|--------|------|-------------|
| `user_id` | INTEGER | PRIMARY KEY (composite) |
| `repo_id` | INTEGER | PRIMARY KEY (composite) |

#### `issues` *(Future Implementation)*
| Column | Type | Constraints |
|--------|------|-------------|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT |
| `repo_id` | INTEGER | FOREIGN KEY → repositories(id) |
| `user_id` | INTEGER | FOREIGN KEY → users(id) |
| `title` | TEXT | NOT NULL |
| `body` | TEXT | DEFAULT '' |
| `state` | TEXT | DEFAULT 'open' |
| `created_at` | INTEGER | Unix timestamp |

---

## 🔒 Security Features

### Access Control
- **Path Traversal Prevention** — `realpath()` validation on all file operations
- **Ownership Verification** — Strict user-repository ownership checks
- **Session Management** — Secure PHP sessions with HTTP-only cookies

### Input Sanitization
- **XSS Protection** — `htmlspecialchars(ENT_QUOTES)` on all user output
- **SQL Injection Prevention** — Parameterized PDO queries throughout
- **File Type Validation** — MIME type checking for ZIP uploads

### HTTP Security Headers
```apache
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
```

### File Protection
- **Direct Access Blocking** — `.htaccess`/nginx rules prevent access to:
  - `/repos/` — Repository storage
  - `/uploads/` — Temporary files
  - `/cache/` — Cache data
  - `/includes/` — Source code
  - `/gitphp.db` — Database file

### Upload Security
- **Size Limits** — 100MB hard limit enforced before extraction
- **MIME Validation** — ZIP-only file type verification
- **Pattern Filtering** — Automatic exclusion of sensitive files

---

## ⚡ Performance Tuning

### SQLite Optimizations
```php
PRAGMA journal_mode=WAL;      // Write-Ahead Logging
PRAGMA synchronous=NORMAL;    // Balanced durability/speed
PRAGMA cache_size=10000;      // 10MB page cache
```

### Caching Strategy
- **Markdown Cache** — Rendered HTML stored with MD5 hash keys
- **Stats Cache** — Repository statistics cached for 60 seconds
- **Cache Invalidation** — Automatic clearing on repository updates

### Compression
- **Gzip Output** — Level 6 compression for text-based responses
- **Static Asset Caching** — 7-day browser cache for CSS/JS/fonts

---

## 🎯 Usage Examples

### Creating Your First Repository

1. **Register an Account**
   ```
   Navigate to / → Click "Sign Up" → Enter credentials
   ```

2. **Create a Repository**
   ```
   Click "+" → Enter name → Add description → Choose visibility → Create
   ```

3. **Upload Code**
   ```
   Go to repo page → Click "Upload ZIP" → Drag & drop → Extract
   ```

4. **Edit Files**
   ```
   Browse to file → Click filename → Edit → Ctrl+S to save
   ```

### Programmatic Usage

```bash
# Clone the platform itself
git clone https://github.com/nexuss0781/codespacehub.git
cd codespacehub

# Deploy via rsync
rsync -avz gitphp/ user@server:/var/www/gitphp/

# Or use scp
scp -r gitphp/ user@server:/var/www/
```

---

## 🐛 Troubleshooting

### Common Issues

**1. Permission Denied Errors**
```bash
chmod 777 repos/ uploads/ cache/
chown -R www-data:www-data .
```

**2. mod_rewrite Not Working**
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

**3. Database Lock Issues**
```bash
# Ensure WAL mode is active
sqlite3 gitphp.db "PRAGMA journal_mode;"
# Should return: wal
```

**4. Upload Fails Silently**
- Check `php.ini`: `upload_max_filesize = 105M`, `post_max_size = 105M`
- Verify `uploads/` directory is writable

**5. Blank Page / White Screen**
```bash
# Enable error reporting temporarily
php -d display_errors=1 -S localhost:8080 router.php
```

---

## 🤝 Contributing

Contributions are welcome! To contribute:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Guidelines
- Follow PSR-12 coding standards
- No external dependencies (Composer packages)
- Maintain backward compatibility
- Document all public functions

---

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.

```
Copyright (c) 2024 CodespaceHub

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## 📬 Support

- **Issues:** [GitHub Issues](https://github.com/nexuss0781/codespacehub/issues)
- **Discussions:** [GitHub Discussions](https://github.com/nexuss0781/codespacehub/discussions)
- **Email:** nexuss0781@gmail.com

---

<div align="center">

**Built with ❤️ using pure PHP**

[⬆ Back to Top](#codespacehub--enterprise-grade-self-hosted-code-repository-platform)

</div>
