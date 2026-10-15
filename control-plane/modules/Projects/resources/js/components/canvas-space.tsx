import { useStoreApi } from '@xyflow/react';
import { memo, useEffect, useRef, type CSSProperties } from 'react';

/** Source image size (public/images/canvas/space.*); moons are cut out of it into moons.webp (40 px cells). */
const WIDTH = 1554;
const HEIGHT = 1012;
const MOONS = [
    { x: 1459, y: 297, duration: 46, delay: -8 },
    { x: 1261, y: 434, duration: 38, delay: -21 },
    { x: 1444, y: 620, duration: 54, delay: -3 },
    { x: 1271, y: 863, duration: 42, delay: -30 },
];
/** Parallax: the backdrop follows the viewport at a few percent of the pan, eased to at most ±MAX px. */
const PAN = 0.035;
const MAX = 26;

/** Deterministic star field (same sky on every render and visit). */
function stars(count: number): CSSProperties[] {
    let seed = 0x5eed;
    const random = () => {
        seed = (seed * 1664525 + 1013904223) >>> 0;

        return seed / 2 ** 32;
    };

    return Array.from({ length: count }, () => {
        const size = random() < 0.2 ? 2 : 1;

        return {
            left: `${(random() * 100).toFixed(2)}%`,
            top: `${(random() * 100).toFixed(2)}%`,
            width: size,
            height: size,
            animationDuration: `${(3 + random() * 5).toFixed(1)}s`,
            animationDelay: `${(-random() * 8).toFixed(1)}s`,
        };
    });
}

const STARS = stars(42);

/**
 * The canvas backdrop: the space image (AVIF / WebP / JPEG) under the flow, with twinkling stars, a slowly breathing
 * nebula, floating moons and a light parallax that follows pan and zoom. Transforms and opacity only; paused while the
 * tab is hidden; static under prefers-reduced-motion. Never takes pointer events.
 */
export const CanvasSpace = memo(function CanvasSpace() {
    const ref = useRef<HTMLDivElement>(null);
    const store = useStoreApi();

    useEffect(() => {
        const element = ref.current;
        if (!element) return;
        const pause = () => element.toggleAttribute('data-paused', document.hidden);
        pause();
        document.addEventListener('visibilitychange', pause);

        // The viewport changes on every pan frame: write two custom properties, no React render.
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        let unsubscribe: (() => void) | null = null;
        const follow = () => {
            unsubscribe?.();
            unsubscribe = null;
            element.style.removeProperty('--space-x');
            element.style.removeProperty('--space-y');
            element.style.removeProperty('--space-zoom');
            if (reduced.matches) return;
            let last = '';
            const apply = ([x, y, zoom]: [number, number, number]) => {
                const key = `${Math.round(x)}:${Math.round(y)}:${zoom.toFixed(3)}`;
                if (key === last) return;
                last = key;
                element.style.setProperty('--space-x', `${(MAX * Math.tanh((x * PAN) / MAX)).toFixed(2)}px`);
                element.style.setProperty('--space-y', `${(MAX * Math.tanh((y * PAN) / MAX)).toFixed(2)}px`);
                element.style.setProperty('--space-zoom', (1 + (zoom - 1) * 0.03).toFixed(4));
            };
            apply(store.getState().transform);
            unsubscribe = store.subscribe((state) => apply(state.transform));
        };
        follow();
        reduced.addEventListener('change', follow);

        return () => {
            document.removeEventListener('visibilitychange', pause);
            reduced.removeEventListener('change', follow);
            unsubscribe?.();
        };
    }, [store]);

    return (
        <div ref={ref} className="falak-space" aria-hidden>
            <div className="falak-space-stage" style={{ aspectRatio: `${WIDTH} / ${HEIGHT}` }}>
                <picture>
                    <source srcSet="/images/canvas/space.avif" type="image/avif" />
                    <source srcSet="/images/canvas/space.webp" type="image/webp" />
                    <img src="/images/canvas/space.jpg" alt="" width={WIDTH} height={HEIGHT} decoding="async" draggable={false} />
                </picture>
                <span className="falak-space-glow" style={{ left: '84%', top: '30%', animationDelay: '-7s' }} />
                <span className="falak-space-glow" style={{ left: '16%', top: '66%', animationDuration: '27s' }} />
                {MOONS.map((moon, index) => (
                    <span
                        key={index}
                        className="falak-space-moon"
                        style={{
                            left: `${((moon.x - 20) / WIDTH) * 100}%`,
                            top: `${((moon.y - 20) / HEIGHT) * 100}%`,
                            backgroundPosition: `${(index * 100) / (MOONS.length - 1)}% 0`,
                            animationDuration: `${moon.duration}s`,
                            animationDelay: `${moon.delay}s`,
                        }}
                    />
                ))}
            </div>
            <div className="falak-space-stars">
                {STARS.map((style, index) => (
                    <span key={index} style={style} />
                ))}
            </div>
        </div>
    );
});
