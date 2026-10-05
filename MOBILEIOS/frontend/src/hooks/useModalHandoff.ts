import { useCallback, useEffect, useRef } from 'react';

const DISMISS_FALLBACK_MS = 800;

export const useModalHandoff = () => {
  const queue = useRef<Array<() => void>>([]);
  const fallback = useRef<ReturnType<typeof setTimeout> | null>(null);

  const flush = useCallback(() => {
    if (fallback.current) {
      clearTimeout(fallback.current);
      fallback.current = null;
    }
    const steps = queue.current;
    queue.current = [];
    steps.forEach((step) => step());
  }, []);

  const after = useCallback((next: () => void) => {
    queue.current.push(next);
    if (!fallback.current) {
      fallback.current = setTimeout(flush, DISMISS_FALLBACK_MS);
    }
  }, [flush]);

  const dismissed = useCallback(() => new Promise<void>((resolve) => after(resolve)), [after]);

  useEffect(() => () => {
    if (fallback.current) clearTimeout(fallback.current);
  }, []);

  return { after, dismissed, onDismiss: flush };
};
