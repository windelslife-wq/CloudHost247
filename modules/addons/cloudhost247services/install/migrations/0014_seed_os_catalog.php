<?php
/**
 * Seed the initial operating-system catalog: the 12 CloudHost247 OS families
 * with realistic version records, architecture support, LTS flags and
 * lifecycle states.
 *
 * Everything seeded here is editable or deletable from the admin console
 * (Infrastructure → OS Catalog) — this is starter content, not hard-coded
 * product data. No provider image mappings are seeded: an OS version only
 * becomes deployable once an administrator maps and tests a provider image.
 *
 * logo_url points at module-owned assets; administrators can repoint any OS
 * at a different logo from the admin page. The logo is a UI asset only —
 * the deployable installation image is always the provider image mapping.
 */

use Chs\Core\Clock;
use Chs\Core\Db;

return [
    'id' => '0014_seed_os_catalog',
    'description' => 'Seed OS catalog: 12 operating systems with versions, architectures, lifecycle states',
    'up' => function () {
        if (Db::count('operating_systems') > 0) {
            return;
        }
        $now = Clock::now();

        // slug => [name, vendor, description, sort, vps, dedicated, cloud, reinstall,
        //          versions: [version, display, release_name, archs, status, is_default, is_lts, release, eol]]
        $catalog = [
            'ubuntu' => ['Ubuntu', 'Canonical Ltd.', 'The world\'s most popular cloud and server Linux — predictable LTS releases with long support windows.', 10, 1, 1, 1, 1, [
                ['24.04', 'Ubuntu 24.04 LTS', 'Noble Numbat', 'x86_64,arm64', 'ACTIVE', 1, 1, '2024-04-25', '2029-04-30'],
                ['22.04', 'Ubuntu 22.04 LTS', 'Jammy Jellyfish', 'x86_64,arm64', 'ACTIVE', 0, 1, '2022-04-21', '2027-04-30'],
                ['20.04', 'Ubuntu 20.04 LTS', 'Focal Fossa', 'x86_64,arm64', 'EOL', 0, 1, '2020-04-23', '2025-04-30'],
            ]],
            'debian' => ['Debian', 'Debian Project', 'The universal operating system — stable, free, and the base of much of the hosting industry.', 20, 1, 1, 1, 1, [
                ['13', 'Debian 13', 'Trixie', 'x86_64,arm64', 'ACTIVE', 1, 0, '2025-08-09', '2028-06-30'],
                ['12', 'Debian 12', 'Bookworm', 'x86_64,arm64', 'ACTIVE', 0, 1, '2023-06-10', '2026-06-30'],
                ['11', 'Debian 11', 'Bullseye', 'x86_64,arm64', 'EOL_WARNING', 0, 1, '2021-08-14', '2026-08-31'],
            ]],
            'almalinux' => ['AlmaLinux', 'AlmaLinux OS Foundation', 'A free, community-owned RHEL-compatible enterprise Linux — the CentOS successor for hosting fleets.', 30, 1, 1, 1, 1, [
                ['10', 'AlmaLinux 10', '', 'x86_64,arm64', 'ACTIVE', 1, 1, '2025-05-27', '2035-05-31'],
                ['9', 'AlmaLinux 9', '', 'x86_64,arm64', 'ACTIVE', 0, 1, '2022-05-26', '2032-05-31'],
                ['8', 'AlmaLinux 8', '', 'x86_64,arm64', 'EOL', 0, 1, '2021-03-30', '2024-05-31'],
            ]],
            'rocky' => ['Rocky Linux', 'Rocky Enterprise Software Foundation', 'A community enterprise operating system designed to be 100% bug-for-bug compatible with RHEL.', 40, 1, 1, 1, 1, [
                ['9', 'Rocky Linux 9', '', 'x86_64,arm64', 'ACTIVE', 1, 1, '2022-07-14', '2032-05-31'],
                ['8', 'Rocky Linux 8', '', 'x86_64,arm64', 'EOL', 0, 1, '2021-05-01', '2024-05-31'],
            ]],
            'alpine' => ['Alpine Linux', 'Alpine Linux Project', 'A security-oriented, lightweight Linux — small images, fast boots, ideal for containers and small VPS.', 50, 1, 0, 1, 1, [
                ['3.22', 'Alpine Linux 3.22', '', 'x86_64,arm64', 'ACTIVE', 1, 0, '2025-05-22', '2027-05-31'],
                ['3.21', 'Alpine Linux 3.21', '', 'x86_64,arm64', 'EOL_WARNING', 0, 0, '2024-12-05', '2026-11-01'],
                ['3.20', 'Alpine Linux 3.20', '', 'x86_64,arm64', 'EOL', 0, 0, '2024-05-22', '2026-05-31'],
            ]],
            'arch' => ['Arch Linux', 'Arch Linux', 'A lightweight, flexible rolling-release distribution for administrators who want current software.', 60, 1, 0, 1, 1, [
                ['rolling', 'Arch Linux (rolling)', '', 'x86_64', 'ACTIVE', 1, 0, '', ''],
            ]],
            'centos' => ['CentOS', 'CentOS Project / Red Hat', 'CentOS Stream — the continuously delivered upstream of RHEL; classic CentOS Linux is end-of-life.', 70, 1, 1, 0, 1, [
                ['stream-10', 'CentOS Stream 10', '', 'x86_64,arm64', 'ACTIVE', 1, 0, '2024-12-12', '2030-05-31'],
                ['stream-9', 'CentOS Stream 9', '', 'x86_64,arm64', 'ACTIVE', 0, 0, '2021-12-03', '2027-05-31'],
                ['7', 'CentOS 7', '', 'x86_64', 'EOL', 0, 0, '2014-07-07', '2024-06-30'],
            ]],
            'cloudlinux' => ['CloudLinux', 'CloudLinux Inc.', 'A hardened RHEL-based OS built for shared hosting and multi-tenant servers — per-tenant resource isolation.', 80, 1, 1, 0, 1, [
                ['9', 'CloudLinux 9', '', 'x86_64,arm64', 'ACTIVE', 1, 0, '2022-09-01', '2032-12-31'],
                ['8', 'CloudLinux 8', '', 'x86_64,arm64', 'EOL_WARNING', 0, 0, '2021-12-21', '2025-12-31'],
            ]],
            'fedora-cloud' => ['Fedora Cloud', 'Fedora Project', 'The cloud-optimised Fedora edition — current kernel, cloud-init, minimal footprint, fast release cadence.', 90, 1, 0, 1, 1, [
                ['43', 'Fedora Cloud 43', '', 'x86_64,arm64', 'EOL_WARNING', 1, 0, '2025-10-28', '2026-11-30'],
                ['42', 'Fedora Cloud 42', '', 'x86_64,arm64', 'EOL', 0, 0, '2025-04-15', '2026-05-30'],
            ]],
            'kali' => ['Kali Linux', 'Offensive Security', 'The professional penetration-testing and security-auditing distribution.', 100, 1, 0, 0, 1, [
                ['2025.3', 'Kali Linux 2025.3', '', 'x86_64,arm64', 'ACTIVE', 1, 0, '2025-09-09', ''],
                ['kali-rolling', 'Kali Linux (rolling)', '', 'x86_64,arm64', 'ACTIVE', 0, 0, '', ''],
            ]],
            'nixos' => ['NixOS', 'NixOS Foundation', 'A purely functional, declarative Linux distribution — reproducible server configurations.', 110, 1, 0, 1, 1, [
                ['26.05', 'NixOS 26.05', '', 'x86_64,arm64', 'ACTIVE', 1, 0, '2026-05-01', '2026-12-31'],
                ['25.11', 'NixOS 25.11', '', 'x86_64,arm64', 'EOL_WARNING', 0, 0, '2025-11-01', '2026-05-31'],
            ]],
            'opensuse' => ['openSUSE', 'openSUSE Project', 'The makers\' distribution — Leap for stable servers, Tumbleweed for rolling.', 120, 1, 1, 1, 1, [
                ['leap-16.0', 'openSUSE Leap 16.0', '', 'x86_64,arm64', 'ACTIVE', 1, 0, '2025-10-01', '2027-11-30'],
                ['tumbleweed', 'openSUSE Tumbleweed', '', 'x86_64,arm64', 'ACTIVE', 0, 0, '', ''],
                ['leap-15.6', 'openSUSE Leap 15.6', '', 'x86_64,arm64', 'EOL', 0, 0, '2024-06-05', '2025-12-31'],
            ]],
        ];

        foreach ($catalog as $slug => $os) {
            list($name, $vendor, $description, $sort, $vps, $dedicated, $cloud, $reinstall, $versions) = $os;
            $osId = Db::insert('operating_systems', [
                'name'                   => $name,
                'slug'                   => $slug,
                'vendor'                 => $vendor,
                'description'            => $description,
                'logo_url'               => 'modules/addons/cloudhost247services/assets/img/os/' . $slug . '.svg',
                'status'                 => 'ACTIVE',
                'sort_order'             => $sort,
                'is_vps_supported'       => $vps,
                'is_dedicated_supported' => $dedicated,
                'is_cloud_supported'     => $cloud,
                'is_reinstall_supported' => $reinstall,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
            foreach ($versions as $v) {
                list($version, $display, $releaseName, $archs, $status, $isDefault, $isLts, $releaseDate, $eol) = $v;
                Db::insert('operating_system_versions', [
                    'operating_system_id'    => $osId,
                    'version'                => $version,
                    'display_name'           => $display,
                    'release_name'           => $releaseName,
                    'architecture_support'   => json_encode(array_values(array_filter(array_map('trim', explode(',', $archs))))),
                    'status'                 => $status,
                    'is_default'             => $isDefault,
                    'is_lts'                 => $isLts,
                    'release_date'           => $releaseDate !== '' ? $releaseDate : null,
                    'end_of_life_date'       => $eol !== '' ? $eol : null,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ]);
            }
        }
    },
];
