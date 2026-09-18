import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // Stable on Windows workstations with limited process-spawn capacity.
    maxWorkers: 1,
  },
});
