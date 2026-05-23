# GitPHP - Modern Code Repository Platform

A lightweight, self-hosted Git repository management system built with PHP and SQLite. Designed for developers who want a clean, minimalist interface for managing code repositories.

## Features

### 🎯 Core Functionality
- **Repository Management**: Full CRUD operations for creating, viewing, updating, and deleting repositories
- **Code-Focused Views**: Smart file filtering that shows only source code and essential documentation
- **File Operations**: Upload ZIP files, create/edit files directly in the browser
- **Markdown Rendering**: Beautiful README rendering with syntax highlighting

### 🔐 Access Control
- **Public/Private Repositories**: Toggle visibility per repository
- **User Authentication**: Secure login/registration system
- **Share Links**: Generate time-limited share tokens for private repos

### 💬 Real-Time Communication
- **Direct Messaging**: Chat with other users in real-time
- **Online Status**: See when users are active
- **Typing Indicators**: Know when someone is composing a message
- **Unread Counts**: Badge notifications for new messages

### ⭐ Social Features
- **Star Repositories**: Bookmark and show appreciation for projects
- **User Profiles**: Customizable bios and avatars
- **Activity Feeds**: Track recent updates across repositories

### 📊 Repository Types
- **Code Repositories**: Default type for source code projects
- **Project Galleries**: Collection-style repos with tabbed views (planned)
- **Documentation Hubs**: Focused on technical documentation

### 🎨 Design Principles
- **Minimalist UI**: Clean, professional interface with intuitive navigation
- **Responsive Layout**: Works on desktop and mobile devices
- **Icon-Based Navigation**: Lucide icons for visual clarity
- **Dark/Light Themes**: Easy on the eyes for extended coding sessions

## Smart File Filtering

GitPHP intelligently filters repository contents to show what matters:

### ✅ Always Shown
- Source code files (`.php`, `.js`, `.py`, `.go`, `.rs`, etc.)
- Configuration files (`.json`, `.yaml`, `.toml`, `.xml`)
- Essential documentation (`README.md`, `LICENSE`, `CONTRIBUTING.md`)
- Build scripts and shell scripts

### ❌ Automatically Hidden
- Media files: Videos (`.mp4`, `.avi`), Audio (`.mp3`, `.wav`)
- Large images: Photos (`.jpg`, `.png`, `.gif`)
- Build artifacts: `node_modules/`, `vendor/`, `dist/`, `build/`
- IDE files: `.vscode/`, `.idea/`, `*.swp`
- Cache files: `__pycache__/`, `.cache/`, `*.log`

This ensures your repository view stays focused on actual source code.

## Tech Stack

- **Backend**: PHP 8+ with PDO
- **Database**: SQLite (no setup required)
- **Frontend**: Vanilla JavaScript, CSS3
- **Icons**: Lucide Icons
- **Markdown**: Custom parser with GitHub-flavored syntax

## Installation

1. Clone the repository:
```bash
git clone https://github.com/nexuss0781/codespacehub.git
cd codespacehub
```

2. Ensure write permissions:
```bash
chmod -R 755 .
chmod -R 777 cache uploads repos
```

3. Start PHP server:
```bash
php -S localhost:8000
```

4. Visit `http://localhost:8000` in your browser

## Project Structure

```
codespacehub/
├── index.php           # Main application router and views
├── README.md           # This file
├── includes/
│   ├── config.php      # Configuration and database setup
│   ├── controllers.php # Request handlers and business logic
│   ├── repofs.php      # Repository filesystem operations
│   └── markdown.php    # Markdown parsing engine
├── repos/              # Stored repositories (auto-created)
├── uploads/            # Temporary upload storage
├── cache/              # Query cache files
└── gitphp.db           # SQLite database (auto-created)
```

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/create-repo` | POST | Create new repository |
| `/api/update-repo` | POST | Update repository details |
| `/api/delete-repo` | POST | Delete repository |
| `/api/star` | POST | Toggle star on repository |
| `/api/upload-zip` | POST | Upload and extract ZIP |
| `/api/save-file` | POST | Save/create a file |
| `/api/delete-file` | POST | Delete a file |
| `/api/send-message` | POST | Send chat message |
| `/api/get-messages/{user}` | GET | Fetch conversation |
| `/api/share-repo` | POST | Generate share link |
| `/api/online-status/{userId}` | GET | Check user status |
| `/api/ping` | POST | Keep session alive |

## Configuration

Edit `includes/config.php` to customize:

- `MAX_FILE_SIZE`: Maximum upload size (default: 100MB)
- `IGNORE_PATTERNS`: Files/directories to hide from views
- `ESSENTIAL_FILES`: Documentation files to always show

## Security Notes

- Passwords are hashed with bcrypt
- SQL injection prevented via prepared statements
- Path traversal attacks blocked in file operations
- Session-based authentication
- Share tokens expire after 7 days by default

## Roadmap

- [ ] WebSocket support for true real-time chat
- [ ] Git protocol implementation (push/pull)
- [ ] CI/CD integration hooks
- [ ] Plugin system for extensibility
- [ ] Docker containerization
- [ ] Multi-language UI translations

## License

MIT License - See LICENSE file for details

## Contributing

Contributions welcome! Please read CONTRIBUTING.md before submitting PRs.

---

Built with ❤️ by nexuss0781
