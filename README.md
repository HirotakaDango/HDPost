# HDPost

<img width="1280" height="720" alt="17887601456111786140878935965884" src="https://github.com/user-attachments/assets/1e3a2760-b4ef-4f50-987f-aaa419989355" />

HDPost is a self-contained, single-file PHP and SQLite creative illustration studio and artwork archive. It provides an art-sharing platform (similar to Pixiv or ArtStation) with zero external server dependencies, responsive client-side routing, and modern media processing.

---

### Key Features

- **Self-Contained Architecture:** Entire backend API, database initialization, front-end SPA (Vanilla JS + CSS), and PWA service worker run out of a single file (`index.php`).
- **Chunked File Uploads:** Uploads large image and video files in 2MB chunks to bypass strict server upload limits.
- **Visual Perceptual Search:** Computes 64-bit perceptual hashes (`pHash`) to match visually similar images using Hamming distance calculations.
- **Artwork Management:** Supports multi-page illustration sets (up to 500 images with a "See All" viewer), batch uploads, and video playback (MP4, WebM, MOV).
- **Feeds & Discovery:** Home feed, Hall of Fame rankings (daily/weekly/monthly), R-18 filter, and searchable directories for tags, characters, series/parodies, and artists.
- **Social Features:** Like system, artist following, and threaded nested commentary supporting Markdown formatting with DOMPurify sanitization.
- **Administration & Moderation:** Role hierarchy (Super Admin, Admins, Artists), account banning, artwork deletion, comment moderation, and storage tracking.
- **Security:** Built-in CSRF token validation, IP-based rate limiting, PDO parameterized queries, EXIF orientation fixes, and `.htaccess` file-access denial for data directories.

---

### System Requirements

- **PHP:** 7.4 or 8.0+
- **PHP Extensions:** `pdo_sqlite`, `gd`, `fileinfo`, `mbstring`
- **Web Server:** Apache (with `mod_rewrite` optional), Nginx, or PHP's built-in development server.
- **Storage:** Read/write permissions in the script's directory.

---

### Quick Installation

1. Place `index.php` in your web server's public document root (or desired subfolder).
2. Ensure PHP has write permissions in the directory.
3. Open the URL in your web browser (e.g., `http://localhost/index.php` or `php -S 127.0.0.1:8000`).
4. On the first launch, the Setup Wizard will prompt you to create the initial Super Administrator account.
5. The application will automatically create and secure the `hdpost_data/` directory containing the SQLite database, uploads, and thumbnail cache.
