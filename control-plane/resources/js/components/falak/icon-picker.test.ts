import { describe, expect, it } from 'vitest';
import { searchIcons } from './icon-picker';
import { coolIconUrl } from './service-icon';

type Row = [string, string, string, string, string, number];

const rows: Row[] = [
    ['postgresql', 'PostgreSQL', 'postgres pg', 'Database', 'svgl', 9],
    ['docker', 'Docker', '', 'Software', 'svgl', 12],
    ['dockerhub', 'Docker Hub', '', '', 'logos', 3],
    ['podman', 'Podman', '', 'Containers', 'logos', 2],
];

describe('searchIcons', () => {
    it('ranks exact, then prefix, then substring and alias, then category matches', () => {
        expect(searchIcons(rows, 'docker').map((row) => row[0])).toEqual(['docker', 'dockerhub']);
        expect(searchIcons(rows, 'postgres').map((row) => row[0])).toEqual(['postgresql']);
        expect(searchIcons(rows, 'pg').map((row) => row[0])).toEqual(['postgresql']);
        expect(searchIcons(rows, 'containers').map((row) => row[0])).toEqual(['podman']);
        expect(searchIcons(rows, 'nothing-like-it')).toEqual([]);
        // A category match is only a fallback.
        expect(
            searchIcons([...rows, ['kube', 'Kube', '', '', 'svgl', 1], ['acme', 'Acme', '', 'Kubernetes Partner', 'cncf', 1]], 'kube').map(
                (row) => row[0],
            ),
        ).toEqual(['kube']);
    });

    it('lists the index as is for an empty query, up to the limit', () => {
        expect(searchIcons(rows, '  ', 2).map((row) => row[0])).toEqual(['postgresql', 'docker']);
    });
});

describe('coolIconUrl', () => {
    it('builds the SVG URL of a slug or slug/variant, and nothing when off or not a key', () => {
        expect(coolIconUrl('https://coolicon.falak.sh', 'docker')).toBe('https://coolicon.falak.sh/i/docker.svg');
        expect(coolIconUrl('https://coolicon.falak.sh', 'docker/svgl-dark')).toBe('https://coolicon.falak.sh/i/docker/svgl-dark.svg');
        expect(coolIconUrl(null, 'docker')).toBeNull();
        expect(coolIconUrl('https://coolicon.falak.sh', '../etc/passwd')).toBeNull();
        expect(coolIconUrl('https://coolicon.falak.sh', '')).toBeNull();
    });
});
