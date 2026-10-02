-- =====================================================================
-- TikTok · Facebook · YouTube Downloader Bot — Database Schema
-- Target: Supabase (PostgreSQL)
--
-- Run this whole file in the Supabase SQL editor (or `psql`) once per
-- project. It is idempotent — safe to re-run.
--
-- Notes:
--   * Row Level Security is ENABLED on every table with no policies,
--     so anon/authenticated keys can read nothing. The app connects
--     with the service-role key, which bypasses RLS by design.
--   * Two helper functions back the bits Supabase's query builder
--     can't express: atomic daily counter increments (`stat_bump`) and
--     the GROUP BY + JOIN + COUNT behind /top (`top_downloaders`).
-- =====================================================================

-- ---------------------------------------------------------------------
-- users: every Telegram user who has talked to the bot
-- ---------------------------------------------------------------------
create table if not exists public.users (
    id            bigint generated always as identity primary key,
    telegram_id   bigint not null unique,
    username      text null,
    first_name    text null,
    last_name     text null,
    is_banned     boolean not null default false,
    joined_at     timestamptz not null default now(),
    last_active_at timestamptz not null default now()
);
create index if not exists idx_users_is_banned on public.users (is_banned);

-- ---------------------------------------------------------------------
-- admins: telegram_ids with admin access (beyond the bootstrap
-- ADMIN_TELEGRAM_ID in .env)
-- ---------------------------------------------------------------------
create table if not exists public.admins (
    id          bigint generated always as identity primary key,
    telegram_id bigint not null unique,
    added_at    timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- downloads: a log of every completed download, used for /history and
-- daily statistics
-- ---------------------------------------------------------------------
create table if not exists public.downloads (
    id         bigint generated always as identity primary key,
    user_id    bigint not null,
    url        text not null,
    type       text not null check (type in
        ('video','image','youtube_audio','youtube_video',
         'facebook_video','facebook_audio','tiktok_audio','youtube_link')),
    created_at timestamptz not null default now()
);
create index if not exists idx_downloads_user_id on public.downloads (user_id);
create index if not exists idx_downloads_created on public.downloads (created_at);

-- ---------------------------------------------------------------------
-- required_channels: channels a user must join before downloading
-- (force-join feature)
-- ---------------------------------------------------------------------
create table if not exists public.required_channels (
    id              bigint generated always as identity primary key,
    channel_username text not null,
    channel_title   text null,
    added_at        timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- settings: simple key/value store for feature toggles
-- (force_join_enabled, maintenance_mode, ...)
-- ---------------------------------------------------------------------
create table if not exists public.settings (
    id            bigint generated always as identity primary key,
    setting_key   text not null unique,
    setting_value text null
);

-- ---------------------------------------------------------------------
-- statistics: one row per calendar day, incremented as events happen
-- ---------------------------------------------------------------------
create table if not exists public.statistics (
    id               bigint generated always as identity primary key,
    stat_date        date not null unique,
    downloads_count  integer not null default 0,
    new_users_count  integer not null default 0
);

-- ---------------------------------------------------------------------
-- logs: application log (errors, API failures, admin actions)
-- ---------------------------------------------------------------------
create table if not exists public.logs (
    id         bigint generated always as identity primary key,
    level      text not null default 'info' check (level in ('info','warning','error')),
    message    text not null,
    context    text null,
    created_at timestamptz not null default now()
);
create index if not exists idx_logs_level on public.logs (level);
create index if not exists idx_logs_created on public.logs (created_at);

-- ---------------------------------------------------------------------
-- cache: generic TTL cache — TikWM / Tool77 / TikTok audio responses
-- and stashed Telegram callback payloads
-- ---------------------------------------------------------------------
create table if not exists public.cache (
    id          bigint generated always as identity primary key,
    cache_key   text not null unique,
    cache_value text not null,
    expires_at  timestamptz not null
);
create index if not exists idx_cache_expires on public.cache (expires_at);

-- ---------------------------------------------------------------------
-- pending_requests: one unresolved request per user, held while they
-- complete the force-join flow, then resumed
-- ---------------------------------------------------------------------
create table if not exists public.pending_requests (
    id         bigint generated always as identity primary key,
    user_id    bigint not null unique,
    url        text not null,
    chat_id    bigint not null,
    created_at timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- youtube_downloads: YouTube conversions polled later by
-- bin/poll-single.ts
-- ---------------------------------------------------------------------
create table if not exists public.youtube_downloads (
    id            bigint generated always as identity primary key,
    user_id       bigint not null,
    chat_id       bigint not null,
    title         text null,
    progress_url  text not null,
    status        text not null default 'pending' check (status in ('pending','ready')),
    download_url  text null,
    thumbnail_url text null,
    created_at    timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- ads: stored forwarded messages an admin adds
-- ---------------------------------------------------------------------
create table if not exists public.ads (
    id                bigint generated always as identity primary key,
    source_chat_id    bigint not null,
    source_message_id bigint not null,
    added_by          bigint not null,
    created_at        timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- pending_broadcasts: one row per admin currently "armed" for /forward
-- ---------------------------------------------------------------------
create table if not exists public.pending_broadcasts (
    admin_id    bigint primary key,
    created_at  timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- seed defaults so the bot behaves sanely on first boot
-- ---------------------------------------------------------------------
insert into public.settings (setting_key, setting_value) values
    ('force_join_enabled', '0'),
    ('maintenance_mode', '0'),
    ('ads_enabled', '0')
on conflict (setting_key) do nothing;

-- ---------------------------------------------------------------------
-- stat_bump: atomic daily counter increment. A read-then-write in the
-- app would race under concurrent webhook requests; ON CONFLICT DO
-- UPDATE ... + excluded does it in one statement.
-- ---------------------------------------------------------------------
create or replace function public.stat_bump(
    p_date date,
    p_downloads integer default 0,
    p_new_users integer default 0
)
returns void
language sql
as $$
    insert into public.statistics (stat_date, downloads_count, new_users_count)
    values (p_date, p_downloads, p_new_users)
    on conflict (stat_date) do update
        set downloads_count = public.statistics.downloads_count + excluded.downloads_count,
            new_users_count = public.statistics.new_users_count + excluded.new_users_count;
$$;

-- ---------------------------------------------------------------------
-- top_downloaders: GROUP BY + JOIN + COUNT for /top — Supabase's query
-- builder can't express this in a single round trip.
-- ---------------------------------------------------------------------
create or replace function public.top_downloaders(p_limit integer default 10)
returns table (
    telegram_id bigint,
    username    text,
    downloads   bigint
)
language sql
as $$
    select u.telegram_id, u.username, count(d.id) as downloads
    from public.downloads d
    join public.users u on u.telegram_id = d.user_id
    group by u.telegram_id, u.username
    order by downloads desc
    limit p_limit;
$$;

-- ---------------------------------------------------------------------
-- Row Level Security: locked down with no policies. The service-role
-- key bypasses RLS; every other key sees nothing.
-- ---------------------------------------------------------------------
alter table public.users              enable row level security;
alter table public.admins             enable row level security;
alter table public.downloads          enable row level security;
alter table public.required_channels  enable row level security;
alter table public.settings           enable row level security;
alter table public.statistics         enable row level security;
alter table public.logs               enable row level security;
alter table public.cache              enable row level security;
alter table public.pending_requests   enable row level security;
alter table public.youtube_downloads  enable row level security;
alter table public.ads                enable row level security;
alter table public.pending_broadcasts enable row level security;
