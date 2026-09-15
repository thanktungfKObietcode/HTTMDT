<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontFooterTest extends TestCase
{
    use RefreshDatabase;

    public function test_storefront_footer_uses_real_routes_and_supported_content_only(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        preg_match('/<footer class="site-footer".*?<\/footer>/s', $response->getContent(), $matches);
        $footer = $matches[0] ?? '';

        $this->assertStringContainsString('Đăng ký nhận bản tin', $footer);
        $this->assertStringContainsString(route('newsletter.subscribe'), $footer);
        $this->assertStringContainsString(route('contact'), $footer);
        $this->assertStringContainsString(route('showrooms'), $footer);
        $this->assertStringContainsString(route('policy.show', 'returns'), $footer);
        $this->assertStringContainsString(route('policy.show', 'shipping'), $footer);
        $this->assertStringContainsString(route('policy.show', 'warranty'), $footer);
        $this->assertStringContainsString('COD', $footer);
        $this->assertStringNotContainsString('href="#"', $footer);
        $this->assertStringNotContainsString('Lộc Phúc', $footer);
        $this->assertStringNotContainsString('Bộ Công Thương', $footer);
    }
}
