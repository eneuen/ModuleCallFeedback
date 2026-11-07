<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallFeedback\Lib;

/**
 * The prompts that ship with the module, in English and Russian.
 *
 * The recordings live in Sounds/<language>/<key>.wav. When the module is
 * enabled, the core copies them into Asterisk's sounds folder for that
 * language as "modulecallfeedback-<key>" and converts them to its other
 * formats (SoundFilesConf::installModuleSounds); disabling removes them. The
 * language folders are the codes a survey sets with CHANNEL(language), so
 * Playback(modulecallfeedback-<key>) plays the survey's language with no path
 * in the dialplan.
 *
 * A survey stores a built-in prompt as a negative ID in its usual prompt
 * fields. The PBX's own sound-file IDs are always positive, so the two never
 * clash. Only the names in this fixed list ever reach the dialplan.
 *
 * Plain PHP, no core dependencies, so it is unit-tested without a PBX.
 */
final class BuiltInPrompts
{
    /** The prompt key => its ID in a survey's prompt fields. */
    public const array IDS = [
        'intro'      => -1,
        'thanks'     => -2,
        'invalid'    => -3,
        'q-csat'     => -4,
        'q-nps'      => -5,
        'q-ces'      => -6,
        'q-resolved' => -7,
    ];

    /** How the core names a feature module's sounds: the module ID in lower case, then a dash. */
    public const string NAME_PREFIX = 'modulecallfeedback-';

    /** The folder under the module directory that the core installs sounds from. */
    public const string SOUNDS_DIR = 'Sounds';

    public static function isBuiltIn(int $id): bool
    {
        return in_array($id, self::IDS, true);
    }

    /** The prompt's key, such as "q-csat", or null for any other ID. */
    public static function key(int $id): ?string
    {
        $key = array_search($id, self::IDS, true);

        return is_string($key) ? $key : null;
    }

    public static function id(string $key): int
    {
        return self::IDS[$key] ?? throw new \InvalidArgumentException("Unknown built-in prompt: $key");
    }

    /** The name to Playback, such as "modulecallfeedback-q-csat", or null for any other ID. */
    public static function playbackName(int $id): ?string
    {
        $key = self::key($id);

        return $key === null ? null : self::NAME_PREFIX . $key;
    }

    /**
     * Whether the module ships this prompt's recording in a language.
     *
     * @param string $moduleDir The module's folder.
     */
    public static function available(string $moduleDir, int $id, string $language): bool
    {
        $key = self::key($id);
        if ($key === null || !in_array($language, DialplanBuilder::LANGUAGES, true)) {
            return false;
        }

        return is_file(rtrim($moduleDir, '/') . '/' . self::SOUNDS_DIR . "/$language/$key.wav");
    }

    /**
     * The catalogue for the survey editor, in display order, with the file
     * the editor can preview in each language. The core installs the prompts
     * into its sounds folder and converts them; the browser-friendly .webm
     * copy is preferred, else the shipped .wav.
     *
     * @param string|null $soundsDir The PBX's Asterisk sounds folder, or null for no previews.
     *
     * @return list<array{id: int, key: string, preview: array<string, string>}>
     */
    public static function catalogue(?string $soundsDir = null): array
    {
        $list = [];
        foreach (self::IDS as $key => $id) {
            $preview = [];
            foreach ($soundsDir === null ? [] : DialplanBuilder::LANGUAGES as $language) {
                $base = rtrim($soundsDir, '/') . "/$language/" . self::NAME_PREFIX . $key;
                $preview[$language] = is_file("$base.webm") ? "$base.webm" : "$base.wav";
            }
            $list[] = ['id' => $id, 'key' => $key, 'preview' => $preview];
        }

        return $list;
    }
}
