# sync-bsm2ics

sync BSM (DBV Baseball- und Softball-Manager) matches into a nextcloud calendar (using CALDAV)

## Requirements

- tested with PHP 8.2

## Usage

1. copy `config.example.php` to `config.php` and replace your nextcloud data
2. `php sync_bsm2ics.php <leage_group> <team_id> [match_duration_in_seconds]`

