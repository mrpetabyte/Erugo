<?php

namespace App\Services;

use App\Haikunator;
use App\Models\Share;
use App\Models\ReverseShareInvite;

class LongIdGenerator
{
    private SettingsService $settingsService;
    private PatternGenerator $patternGenerator;

    public function __construct()
    {
        $this->settingsService = new SettingsService();
        $this->patternGenerator = new PatternGenerator();
    }

    public function generateForShare(): string
    {
        return $this->generate(fn($id) => Share::where('long_id', $id)->exists());
    }

    public function generateForInvite(): string
    {
        return $this->generate(fn($id) => ReverseShareInvite::where('invite_code', $id)->exists());
    }

    private function generate(callable $existsCheck): string
    {
        $mode = $this->settingsService->get('share_url_mode') ?? 'haiku';
        $maxAttempts = 10;
        $attempts = 0;

        $id = $this->generateByMode($mode);
        while ($existsCheck($id) && $attempts < $maxAttempts) {
            $id = $this->generateByMode($mode);
            $attempts++;
        }

        if ($attempts >= $maxAttempts) {
            throw new \Exception('Unable to generate unique ID after ' . $maxAttempts . ' attempts');
        }

        return $id;
    }

    private function generateByMode(string $mode): string
    {
        return match ($mode) {
            'pattern' => $this->generatePatternId(),
            default => $this->generateHaikuId(),
        };
    }

    private function generateHaikuId(): string
    {
        return Haikunator::haikunate() . '-' . Haikunator::haikunate();
    }

    private function generatePatternId(): string
    {
        $pattern = $this->settingsService->get('share_url_pattern') ?? '******';

        $error = $this->patternGenerator->validate($pattern);
        if ($error !== null) {
            \Log::warning("Invalid share URL pattern configured: {$error}. Using default.");
            $pattern = '******';
        }

        return $this->patternGenerator->generate($pattern);
    }
}
