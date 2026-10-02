import fs from 'node:fs';
import fsp from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';
import { Config } from '../config.js';
import { logger } from '../helpers/logger.js';

/**
 * Downloads remote media into the temp dir (TEMP_DIR, defaulting to
 * storage/temp/) for the large-video (>20MB) upload path, and cleans up
 * afterwards.
 */
export class MediaService {
  private resolvedTempDir: string | null = null;

  /**
   * Creates the configured temp dir, falling back to os.tmpdir() when it
   * can't be created — serverless platforms mount the app directory
   * read-only, and on those only /tmp is writable. The result is cached
   * so the probe happens once per container.
   */
  private async ensureTempDir(): Promise<string | null> {
    if (this.resolvedTempDir) return this.resolvedTempDir;

    for (const dir of [Config.get<string>('temp_dir'), os.tmpdir()]) {
      try {
        await fsp.mkdir(dir, { recursive: true, mode: 0o775 });
        this.resolvedTempDir = dir;
        return dir;
      } catch (error) {
        logger.write('warning', 'Temp dir unavailable, trying the next candidate', {
          dir,
          error: error instanceof Error ? error.message : String(error),
        });
      }
    }
    return null;
  }

  /**
   * Downloads a remote file into the temp dir and returns its path.
   * referer is needed for googlevideo.com links — Google's CDN rejects
   * fetches that don't carry a youtube.com Referer.
   */
  async downloadToTemp(url: string, extension = 'mp4', referer: string | null = null): Promise<string | null> {
    try {
      const tempDir = await this.ensureTempDir();
      if (!tempDir) {
        throw new Error('no writable temp directory');
      }
      const filePath = path.join(
        tempDir,
        `media_${Date.now()}_${Math.random().toString(36).slice(2)}.${extension}`,
      );

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
      return null;
    }
  }

  async cleanup(filePath: string): Promise<void> {
    await fsp.rm(filePath, { force: true }).catch(() => undefined);
  }

  /** Sweeps the configured temp dir of anything older than maxAgeSeconds — wire this into a cron job. */
  async cleanupOldTempFiles(maxAgeSeconds = 3600): Promise<void> {
    const dir = Config.get<string>('temp_dir');
    let entries: string[] = [];
    try {
      entries = await fsp.readdir(dir);
    } catch {
      return;
    }

    const cutoff = Date.now() - maxAgeSeconds * 1000;
    for (const entry of entries) {
      const filePath = path.join(dir, entry);
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
