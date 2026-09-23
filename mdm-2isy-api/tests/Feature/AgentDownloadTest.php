<?php

namespace Tests\Feature;

use Tests\TestCase;

class AgentDownloadTest extends TestCase
{
    public function test_latest_android_agent_can_be_downloaded_from_the_stable_url(): void
    {
        $response = $this->get('/download/mdm-agent.apk');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive')
            ->assertHeader('content-disposition', 'attachment; filename=2ISY-MDM-Agent.apk');

        $cacheControl = (string) $response->headers->get('cache-control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_short_download_url_redirects_to_the_apk(): void
    {
        $this->get('/download/mdm-agent')
            ->assertRedirect('/download/mdm-agent.apk');
    }
}
