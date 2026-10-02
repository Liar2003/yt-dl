import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { Config } from '../config.js';
import { logger } from '../helpers/logger.js';

/**
 * Downloads remote media into storage/temp/ for the large-video
 * (>20MB) upload path, and cleans up afterwards.
 */
export class MediaService {
  private readonly tempDir: string;

  constructor() {
    this.tempDir = path.join(Config.get<string>('root_dir'), 'storage', 'temp');
  }

  /**
   * Downloads a remote file into storage/temp/ and returns its path.
   * referer is needed for googlevideo.com links — Google's CDN rejects
   * fetches that don't carry a youtube.com Referer.
   */
  async downloadToTemp(url: string, extension = 'mp4', referer: string | null = null): Promise<string | null> {
    await fsp.mkdir(this.tempDir, { recursive: true, mode: 0o775 });
    const filePath = path.join(this.tempDir, `media_${Date.now()}_${Math.random().toString(36).slice(2)}.${extension}`);

    try {
      const headers: Record<string, string> = { 'User-Agent': 'Mozilla/5.0' };
      if (referer) headers.Referer = referer;

      const response = await fetch(url, {
        headers,
        redirect: 'follow',
        signal: AbortSignal.timeout(120_000),
      });
      if (!response.ok || !response.body) {
        throw new Error(`HTTP ${response.status}`);
      }

      await pipeline(Readable.fromWeb(response.body as any), fs.createWriteStream(filePath));
      return filePath;
    } catch (error) {
      logger.write('error', `MediaService download failed: ${error instanceof Error ? error.message : error}`, {
        url,
      });
      await fsp.rm(filePath, { force: true }).catch(() => undefined);
      return null;
    }
  }

  async cleanup(filePath: string): Promise<void> {
    await fsp.rm(filePath, { force: true }).catch(() => undefined);
  }

  /** Sweeps storage/temp/ of anything older than maxAgeSeconds — wire this into a cron job. */
  async cleanupOldTempFiles(maxAgeSeconds = 3600): Promise<void> {
    let entries: string[] = [];
    try {
      entries = await fsp.readdir(this.tempDir);
    } catch {
      return;
    }

    const cutoff = Date.now() - maxAgeSeconds * 1000;
    for (const entry of entries) {
      const filePath = path.join(this.tempDir, entry);
      try {
        const stat = await fsp.stat(filePath);
        if (stat.isFile() && stat.mtimeMs < cutoff) {
          await fsp.rm(filePath, { force: true });
        }
      } catch {
        // A file that vanished mid-sweep is fine.
      }
    }
  }
}
