import { echo, echoIsConfigured } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

/** Listen on a private channel when realtime is configured; no-op otherwise. */
export function useLive(channel: string, event: string, onEvent: () => void): boolean {
    const cb = useRef(onEvent);
    cb.current = onEvent;
    const enabled = echoIsConfigured();
    useEffect(() => {
        if (!enabled) return;
        const ch = echo().private(channel);
        ch.listen(event, () => cb.current());

        return () => echo().leave(channel);
    }, [channel, event, enabled]);

    return enabled;
}
