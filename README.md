# Canvas Calendar to CSV

A small read-only Canvas calendar exporter based on `index-old.html`. It retrieves the course name and calendar events between inclusive start and end dates, follows Canvas pagination, and downloads a CSV with `Title`, `Description`, `Date`, `Start`, `Duration`, `Location` and `Address` columns. Requests use `per_page=100`; child events are excluded. The filename follows `Canvas Calendar Export - {CourseName} - {CourseID} - {start date} to {end date}.csv`.

## Run locally

Requires Node.js 18 or later (built-in `fetch` is used). From this folder run:

```sh
node server.js
```

Open <http://127.0.0.1:8787>. The local proxy binds to loopback and only relays course detail and calendar event `GET` requests to the selected Canvas instance: `canvas.ox.ac.uk`, `universityofoxford.beta.instructure.com`, `universityofoxford.test.instructure.com`, or `oxe` + `val.instructure.com`. Keep the selector and host allowlists in `index.html` and both proxy files aligned if they change.

The user must provide a Canvas API token with permission to view the selected course calendar. The page keeps it in memory only, sends it as a bearer header, and does not save it. The proxy does not log request headers or store the token.

## PHP deployment

Place `index.html` and `canvas-proxy.php` on the same HTTPS origin with PHP cURL enabled. Configure the PHP runtime variable `CANVAS_TOOL_ORIGIN` to the exact origin serving the page (scheme and host, plus a non-default port if applicable), for example `https://calendar-tools.example.edu`. The PHP proxy rejects requests unless the browser `Origin` header matches that configured value. Protect access to the tool as appropriate for your institution.

The browser sends a same-origin `POST` to the proxy (the proxy performs a read-only Canvas `GET`). The proxy allowlist is intentionally limited to those four Canvas hosts, the course detail endpoint for the requested course ID, the calendar events endpoint, and the parameters required for course/date filtering and pagination. It forwards `Link` and `Retry-After` response headers for pagination and rate-limit visibility.
