# Silentmode Assignment — Server-Triggered File Download from On-Premise Clients

A cloud-hosted Laravel server that can, on demand, download a ~100MB file from any
connected on-premise client — even though those clients sit behind private/NAT
networks with no inbound internet access.

## How it works

Since the server can't open a connection *into* a private network, the client
initiates all connections *out* to the server instead:

1. **Registration** — the client agent (`client/client.php`) registers itself with the
   server once and receives a client ID + secret.
2. **Long-polling** — the client repeatedly calls `GET /api/v1/clients/{id}/poll`, which
   blocks server-side for up to ~28 seconds waiting for a command.
3. **Trigger** — the server queues a download request, either via the API
   (`POST /api/v1/downloads`) or the CLI (`php artisan download:request {client_id}`).
4. **Pickup** — the client's next poll response tells it a download is pending. It
   reads its local `$HOME/file_to_download.txt` and uploads it to the server in
   chunks (`POST /api/v1/downloads/{id}/chunks`), so no single request needs to carry
   the whole 100MB.
5. **Assembly** — the server writes each chunk to disk with `stream_copy_to_stream`
   (never loading the full file into memory) and assembles them into the final file
   once all chunks arrive.
6. **Retrieval** — the completed file can be streamed back out via
   `GET /api/v1/downloads/{id}/file`.

## Layout

```
server/   the cloud-hosted Laravel server (this is "the server" in the scenario)
client/   the on-premise client agent (plain PHP, no framework)
```

## Requirements

- Docker Desktop (with WSL2 integration, if on Windows)
- PHP CLI on the machine that will run the client agent (to simulate the
  on-premise client) — any PHP 7.4+ works, it only needs `curl`.

## Running the server

```bash
git clone <this-repo>
cd silentmode-assignment/server
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

The app listens on `APP_PORT` from `.env` (defaults to 80; if port 80 is already
taken on your machine, set `APP_PORT=8080` in `.env` before running `sail up -d`).

MySQL and Redis are exposed on `FORWARD_DB_PORT` (default 3306) and
`FORWARD_REDIS_PORT` (default 6379) — override either in `.env` if those ports are
already in use locally.

## Running a client

The client agent simulates an on-premise machine. It can run anywhere that can
reach the server over HTTP (it does not need to be reachable itself).

```bash
cd client   # from the repo root, i.e. a sibling of server/
php generate_test_file.php          # creates ~100MB $HOME/file_to_download.txt
php client.php --server=http://localhost:8080 --name="Restaurant ABC"
```

It registers on first run (saving credentials to `client_config.json`) and then
sits in a poll loop, printing upload progress whenever the server requests a file.

## Triggering a download

Run these from `server/`.

List connected clients:

```bash
./vendor/bin/sail artisan clients:list
```

Trigger a download — CLI:

```bash
./vendor/bin/sail artisan download:request {client_id}
```

Trigger a download — API:

```bash
curl -X POST http://localhost:8080/api/v1/downloads \
  -H "Content-Type: application/json" \
  -d '{"client_id": "{client_id}"}'
```

Check progress:

```bash
./vendor/bin/sail artisan download:status {request_id} --watch
```

Or via API:

```bash
curl http://localhost:8080/api/v1/downloads/{request_id}
```

Once `status` is `completed`, fetch the assembled file:

```bash
curl -o downloaded_file.txt http://localhost:8080/api/v1/downloads/{request_id}/file
```

## API reference

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/api/v1/clients/register` | Client registers itself |
| GET | `/api/v1/clients` | List registered clients |
| GET | `/api/v1/clients/{id}/poll` | Client long-polls for pending commands |
| POST | `/api/v1/downloads` | Server queues a download request for a client |
| GET | `/api/v1/downloads` | List all download requests |
| GET | `/api/v1/downloads/{id}` | Check a request's status/progress |
| POST | `/api/v1/downloads/{id}/chunks` | Client uploads one file chunk |
| GET | `/api/v1/downloads/{id}/file` | Retrieve the completed file |
