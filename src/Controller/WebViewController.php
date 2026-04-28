<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WebViewController
{
    #[Route('/tryb-loyalty-webview', methods: 'GET')]
    public function loyaltyWebview(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0c1117">
    <title>TRYB Reward Actions</title>
    <style>
        :root {
            --bg: #f7f3ea;
            --bg-strong: #efe8dc;
            --ink: #101828;
            --muted: #5f6c7b;
            --line: rgba(15, 23, 42, 0.1);
            --card: rgba(255, 255, 255, 0.88);
            --hero: #0c1117;
            --hero-soft: #182330;
            --primary: #0f8a66;
            --primary-deep: #0a684d;
            --secondary: #122b45;
            --secondary-deep: #0c1d30;
            --accent: #d59b2d;
            --shadow: 0 24px 70px rgba(16, 24, 40, 0.13);
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            min-height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(213, 155, 45, 0.16), transparent 26%),
                radial-gradient(circle at top right, rgba(15, 138, 102, 0.12), transparent 28%),
                linear-gradient(180deg, #fbf8f2 0%, var(--bg) 100%);
            color: var(--ink);
        }

        body {
            padding: 18px 14px 28px;
        }

        .shell {
            max-width: 520px;
            margin: 0 auto;
        }

        .hero {
            position: relative;
            overflow: hidden;
            padding: 20px;
            border-radius: 30px;
            background:
                radial-gradient(circle at top right, rgba(15, 138, 102, 0.22), transparent 30%),
                linear-gradient(150deg, #0b1118 0%, #15202d 62%, #182534 100%);
            box-shadow: var(--shadow);
        }

        .hero::after {
            content: "";
            position: absolute;
            inset: auto -50px -90px auto;
            width: 180px;
            height: 180px;
            border-radius: 999px;
            background: rgba(213, 155, 45, 0.16);
            filter: blur(8px);
        }

        .brand-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            position: relative;
            z-index: 1;
        }

        .tryb-logo {
            height: 28px;
            width: auto;
            display: block;
        }

        .badge {
