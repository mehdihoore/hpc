const CACHE_NAME = "timesheet-app-cache-v1"; // Change if you update assets
const urlsToCache = [
  "./", // Alias for index.html
  "./index.html",
  "./manifest.json",
  // Add paths to your CSS files
  "https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.css",
  "./main.min.css",
  // Add paths to your JS files loaded in HTML (if any beyond inline script)
  "https://cdn.tailwindcss.com", // Note: Caching external CDN scripts can be tricky due to opaque responses
  "https://unpkg.com/@majidh1/jalalidatepicker/dist/jalalidatepicker.min.js",
  "https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js",

  // Add paths to your icons (ensure these paths are correct relative to sw.js)
  "./icons/icon-72x72.png",
  "./icons/icon-96x96.png",
  "./icons/icon-128x128.png",
  "./icons/icon-144x144.png",
  "./icons/icon-152x152.png",
  "./icons/icon-192x192.png",
  "./icons/icon-384x384.png",
  "./icons/icon-512x512.png",
  "./icons/maskable_icon_x192.png",
  "./icons/maskable_icon_x512.png",
  // Add paths to your screenshots if you want to cache them
  "./screenshots/screenshot1.png",
  "./screenshots/screenshot2.png",
  // Add any other critical static assets (fonts, other images)
  "https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;700&display=swap", // Caching Google Fonts API response
  // Note: Caching opaque responses from CDNs (like fonts.gstatic.com called by the above CSS)
  // can take up a lot of cache space. More advanced strategies might be needed for those.
];

// Install event: Cache the app shell
self.addEventListener("install", function (event) {
  console.log("[Service Worker] Install");
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then(function (cache) {
        console.log("[Service Worker] Caching app shell");
        // Add all URLs to cache. Be careful with external resources.
        // Using { mode: 'no-cors' } for opaque resources from CDNs if needed,
        // but this means you can't know if the cache was successful or what was cached.
        // For better control, self-host or use workbox.
        const cachePromises = urlsToCache.map((urlToCache) => {
          const request = new Request(urlToCache, { mode: "cors" }); // Default is 'cors'
          return fetch(request)
            .then((response) => {
              if (!response.ok && response.type !== "opaque") {
                // Opaque responses (no-cors) will not be 'ok' but are fine
                console.error(
                  `[Service Worker] Failed to fetch ${urlToCache} - Status: ${response.status}`
                );
                // Don't fail the entire cache operation for one bad resource if not critical
                // Or throw an error to fail: throw new Error(`Failed to fetch ${urlToCache}`);
              }
              // For opaque responses, you can't check response.ok.
              // You might still want to cache them, but be aware of storage implications.
              return cache.put(urlToCache, response);
            })
            .catch((err) => {
              console.error(
                `[Service Worker] Error fetching and caching ${urlToCache}:`,
                err
              );
              // Decide if a failed fetch should prevent SW install
            });
        });
        return Promise.all(cachePromises);
      })
      .then(() => {
        console.log("[Service Worker] App shell cached successfully");
        return self.skipWaiting(); // Activate the new SW immediately
      })
      .catch((error) => {
        console.error("[Service Worker] Caching app shell failed:", error);
      })
  );
});

// Activate event: Clean up old caches
self.addEventListener("activate", function (event) {
  console.log("[Service Worker] Activate");
  event.waitUntil(
    caches
      .keys()
      .then(function (cacheNames) {
        return Promise.all(
          cacheNames.map(function (cacheName) {
            if (cacheName !== CACHE_NAME) {
              console.log("[Service Worker] Removing old cache:", cacheName);
              return caches.delete(cacheName);
            }
          })
        );
      })
      .then(() => {
        console.log(
          "[Service Worker] Activated successfully and old caches removed"
        );
        return self.clients.claim(); // Take control of all open clients
      })
  );
});

// Fetch event: Serve cached content when offline, or fetch from network
self.addEventListener("fetch", function (event) {
  // We only want to handle GET requests for caching
  if (event.request.method !== "GET") {
    return;
  }

  // For API calls (e.g., to your api.php), you usually want a network-first strategy
  // or handle them specifically, not just cache-first like static assets.
  if (
    event.request.url.includes("/api.php") ||
    event.request.url.includes("/holidays.json") ||
    event.request.url.includes("/all_events.json")
  ) {
    event.respondWith(
      fetch(event.request).catch(function () {
        // Optionally, return a custom offline response for API calls
        // return new Response(JSON.stringify({ success: false, message: "Offline" }), {
        //   headers: { 'Content-Type': 'application/json' }
        // });
        // For now, just let it fail if network is unavailable
      })
    );
    return;
  }

  // Cache-First strategy for other requests (app shell)
  event.respondWith(
    caches
      .match(event.request)
      .then(function (response) {
        if (response) {
          // console.log(`[Service Worker] Serving from cache: ${event.request.url}`);
          return response; // Serve from cache
        }
        // console.log(`[Service Worker] Fetching from network: ${event.request.url}`);
        return fetch(event.request).then(function (networkResponse) {
          // Optionally, cache new requests dynamically if they are not in urlsToCache
          // Be careful with this, as it can cache a lot.
          // if (networkResponse && networkResponse.ok) {
          //   const responseToCache = networkResponse.clone();
          //   caches.open(CACHE_NAME).then(function(cache) {
          //     cache.put(event.request, responseToCache);
          //   });
          // }
          return networkResponse;
        });
      })
      .catch(function (error) {
        console.error(
          `[Service Worker] Error in fetch handler for ${event.request.url}:`,
          error
        );
        // You could return a generic offline page here if desired
        // return caches.match('./offline.html'); // You'd need an offline.html page
      })
  );
});
