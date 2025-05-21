# Prevent directory listing
Options -Indexes

# Enable mod_rewrite for URL rewriting
RewriteEngine On
RewriteBase /

# --- SECURITY: Deny Access to sercon directory (if sercon is inside htdocs) ---
# Ensure this is processed before the front controller if sercon is in htdocs
RewriteRule ^sercon(/|$) - [F,L]

# --- NEW: Redirect panel_qr_code.php in root to Fereshteh/panel_qr_code.php ---
# This rule should come BEFORE the general front controller rules
RewriteCond %{REQUEST_URI} ^/panel_qr_code\.php$ [NC]
RewriteCond %{QUERY_STRING} ^id=([0-9]+)$ [NC] # Ensures 'id' is numeric
RewriteRule ^panel_qr_code\.php$ /Fereshteh/panel_qr_code.php?id=%1 [R=301,L,QSA]

# --- Security Headers ---
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"
Header always set X-XSS-Protection "1; mode=block"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://unpkg.com; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net;"
<IfModule mod_headers.c>
   Header set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.jsdelivr.net https://cdn.datatables.net; font-src 'self' https://cdn.jsdelivr.net data:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self';"
</IfModule>
# --- Default Access Control ---
# This is good; it allows access by default.
Require all granted

# --- Rewrite Rules ---
# If the requested file or directory exists, serve it directly. This is
# important for CSS, JS, images, etc.
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# Rewrite everything else to index.php. This is the core of the front controller.
# The [QSA] flag appends any query string parameters to the rewritten URL.
RewriteRule ^ index.php [L,QSA]
php_value upload_max_filesize 100M # Set to a reasonable value (e.g., 20MB)
php_value post_max_size 100M # Should be larger than upload_max_filesize
# --- Error Handling ---
# Correctly handle 403 and 404 errors.
ErrorDocument 403 /error403.php
ErrorDocument 404 /error404.php
AddType text/css .css
# BEGIN cPanel-generated php ini directives, do not edit
# Manual editing of this file may result in unexpected behavior.
# To make changes to this file, use the cPanel MultiPHP INI Editor (Home >> Software >> MultiPHP INI Editor)
# For more information, read our documentation (https://go.cpanel.net/EA4ModifyINI)
<IfModule php8_module>
   php_flag display_errors On
   php_value max_execution_time 600
   php_value max_input_time 600
   php_value max_input_vars 10000
   php_value memory_limit 2048M
   php_value post_max_size 2048M
   php_value session.gc_maxlifetime 86400
   php_value session.save_path "/var/cpanel/php/sessions/ea-php83"
   php_value upload_max_filesize 2048M
   php_flag zlib.output_compression On
</IfModule>
<IfModule lsapi_module>
   php_flag display_errors On
   php_value max_execution_time 600
   php_value max_input_time 600
   php_value max_input_vars 10000
   php_value memory_limit 2048M
   php_value post_max_size 2048M
   php_value session.gc_maxlifetime 86400
   php_value session.save_path "/var/cpanel/php/sessions/ea-php83"
   php_value upload_max_filesize 2048M
   php_flag zlib.output_compression On
</IfModule>
# END cPanel-generated php ini directives, do not edit

# BEGIN cPanel-generated php ini directives, do not edit
# Manual editing of this file may result in unexpected behavior.
# To make changes to this file, use the cPanel MultiPHP INI Editor (Home >> Software >> MultiPHP INI Editor)
# For more information, read our documentation (https://go.cpanel.net/EA4ModifyINI)
<IfModule php8_module>
   php_flag display_errors Off
   php_value max_execution_time 600
   php_value max_input_time 600
   php_value max_input_vars 10000
   php_value memory_limit 2048M
   php_value post_max_size 2048M
   php_value session.gc_maxlifetime 86400
   php_value session.save_path "/var/cpanel/php/sessions/ea-php83"
   php_value upload_max_filesize 2048M
   php_flag zlib.output_compression On
</IfModule>
<IfModule lsapi_module>
   php_flag display_errors Off
   php_value max_execution_time 600
   php_value max_input_time 600
   php_value max_input_vars 10000
   php_value memory_limit 2048M
   php_value post_max_size 2048M
   php_value session.gc_maxlifetime 86400
   php_value session.save_path "/var/cpanel/php/sessions/ea-php83"
   php_value upload_max_filesize 2048M
   php_flag zlib.output_compression On
</IfModule>


# END cPanel-generated php ini directives, do not edit