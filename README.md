# Bi-Tech Illuminate Challenge

A Laravel application built as part of the [Bi-Tech](https://bitech.com.sa) senior Laravel hiring challenge.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Add your challenge token to `.env`:

```env
BITECH_TOKEN=your-token-here
```

Run migrations (SQLite, no additional setup required):

```bash
php artisan migrate
```

## Challenge Commands

### `challenge:fetch-flag`
Connects to the remote PostgreSQL instance via an SSH tunnel and scans all tables for a flag.

```bash
php artisan challenge:fetch-flag
```

### `challenge:inspect`
Inspects the remote database schema and prints a sample row from each table. Useful for exploring the data structure.

```bash
php artisan challenge:inspect
```

### `challenge:import`
Imports neighborhoods and incidents from the remote PostgreSQL into local SQLite. Parses PostgreSQL `polygon` and `point` types, computes neighborhood centroids, and extracts incident codes from JSONB metadata.

```bash
php artisan challenge:import --fresh
```

### `challenge:find-flag`
Queries a neighborhood's incidents via the custom `DonutRelation`, orders them by distance from the centroid, and concatenates their codes to reveal the flag.

```bash
php artisan challenge:find-flag NB-7A2F
```

### `challenge:submit-repo`
Submits the GitHub repository URL and CV to the Bi-Tech challenge API.

```bash
php artisan challenge:submit-repo https://github.com/username/repo /path/to/cv.pdf
```

## Architecture

### `App\Services\SshTunnel`
Handles fetching SSH credentials from the Bi-Tech API, opening an SSH port-forward tunnel to the remote PostgreSQL instance, and cleaning up after use. Accepts a callback so commands stay focused on their own logic.

```php
SshTunnel::connect($token, function (PDO $pdo) {
    // work with the remote database
});
```

### `App\Relations\DonutRelation`
A custom Eloquent relation (extending `Illuminate\Database\Eloquent\Relations\Relation`) that returns incidents within a donut-shaped area (annulus) around a neighborhood's centroid.

- Filtering and ordering are done in PHP using the [Haversine formula](https://en.wikipedia.org/wiki/Haversine_formula)
- Supports both direct access and eager loading via `initRelation` / `match`

```php
// In Neighborhood model:
public function incidents(): DonutRelation
{
    return new DonutRelation($this, innerKm: 0.5, outerKm: 2.0);
}

// Usage:
$neighborhood->incidents()->get(); // Collection<Incident>, ordered by distance
```
# bitech-illuminate-challenge
