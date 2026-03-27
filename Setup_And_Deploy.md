# QA Extraction System - Deployment & Setup Guide

This guide covers setting up the system for local development via XAMPP and deploying it on an Ubuntu VPS without a domain.

---

## 💻 Local Development Setup (XAMPP / Windows)

### 1. Prerequisites
- **XAMPP** (with Apache and MySQL running)
- **Composer** & **PHP** (8.1+)
- **Python** (3.10+) 
- **Redis Server for Windows** (Ensure redis-server is running)

### 2. Configure Environment (.env)
1. Open the `.env` file and set your database connection to MySQL:
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=question_answer
   DB_USERNAME=root
   DB_PASSWORD=
   ```
2. Make sure you add your OpenAI API Key for the Python OCR extraction:
   ```env
   OPENAI_API_KEY=your_openai_api_key_here
   ```
3. Your queuing and cache are configured for Redis:
   ```env
   QUEUE_CONNECTION=redis
   CACHE_STORE=redis
   ```

### 3. Install Requirements
1. **PHP Dependencies:**
   ```bash
   composer install
   ```
2. **Python Dependencies:** Let's set up the python environment. You'll also need Poppler for `pdf2image`.
   ```bash
   pip install -r requirements.txt
   ```
   *(Note for Windows users: You need to download Poppler for Windows and add its `bin/` folder to your system PATH for pdf conversion to work).*

### 4. Database Setup & Storage Link
1. Make sure to create the `question_answer` database in phpMyAdmin.
2. Run database migrations:
   ```bash
   php artisan migrate
   ```
3. Link your storage directory so that the React/Blade frontend can view uploaded PDF images if any:
   ```bash
   php artisan storage:link
   ```

### 5. Running the Application locally
You'll need two separate terminal windows for the queues and server to run simultaneously:

**Terminal 1 (Laravel Server):**
```bash
php artisan serve
```

**Terminal 2 (Queue Worker):**
```bash
php artisan queue:work redis --timeout=14400
```
*(We set timeout to 14400 seconds (4 hours) because OCR processing on large PDFs can take a while).*

You can access the UI at `http://localhost:8000`.

---

## 🚀 VPS Deployment (Ubuntu IP Only)

If you're deploying on a fresh Ubuntu server via IP, run the following:

### 1. Install System Dependencies
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y apache2 mysql-server php php-cli php-mysql php-xml php-curl php-mbstring unzip composer redis-server
sudo apt install -y python3 python3-pip python3-venv poppler-utils tzdata
```

### 2. Setup the Repository and Environment
Clone the project or upload it to `/var/www/question_answer`.
```bash
cd /var/www/question_answer
composer install --optimize-autoloader --no-dev
```
Create and configure your `.env` like in local setup, then generate the App Key:
```bash
php artisan key:generate
php artisan storage:link
php artisan migrate --force
```

### 3. Python Virtual Environment
To safely run python cleanly in the VPS:
```bash
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```
*Note: Make sure your `app/Jobs/ProcessPdfJob.php` changes `['python', $pythonScript, ...]` to point to your `venv` binary (e.g. `['/var/www/question_answer/venv/bin/python', ...]`) on the VPS.*

### 4. Supervisor Configuration for Queue Worker
We want the Laravel worker to keep processing PDF uploads in the background.

```bash
sudo apt install supervisor -y
```

Create a new file `/etc/supervisor/conf.d/qa-worker.conf`:
```ini
[program:qa-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/question_answer/artisan queue:work redis --timeout=14400 --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/question_answer/storage/logs/worker.log
```
Start it up:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start qa-worker:*
```

### 5. Start Server
Normally, you'd route this via an Apache VirtualHost or Nginx. To run it exactly as requested (quick API via IP without domain):
```bash
cd /var/www/question_answer
php artisan serve --host=0.0.0.0 --port=8000
```

The app will be live at `http://YOUR_SERVER_IP:8000`.

### 6. Security/Firewall
Be sure to limit access via UFW:
```bash
sudo ufw allow ssh
sudo ufw allow 8000/tcp
sudo ufw enable
```
