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
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.82);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            backdrop-filter: blur(10px);
        }

        .hero h1 {
            position: relative;
            z-index: 1;
            margin: 18px 0 8px;
            color: #fffaf2;
            font-size: 31px;
            line-height: 1.02;
        }

        .hero p {
            position: relative;
            z-index: 1;
            margin: 0;
            max-width: 92%;
            color: rgba(255, 250, 242, 0.72);
            font-size: 14px;
            line-height: 1.48;
        }

        .stack {
            display: grid;
            gap: 14px;
            margin-top: 16px;
        }

        .panel {
            border-radius: 24px;
            background: var(--card);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(14px);
        }

        .summary {
            padding: 18px;
        }

        .summary-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .eyebrow {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .customer-name {
            margin-top: 6px;
            font-size: 25px;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fff7e8;
            color: #8a5b00;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-pill.ready {
            background: #e9fbf3;
            color: var(--primary-deep);
        }

        .dot {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: currentColor;
            opacity: 0.95;
        }

        .metrics {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 12px;
            margin-top: 16px;
        }

        .metric {
            padding: 16px;
            border-radius: 20px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.7);
        }

        .metric.primary {
            background: linear-gradient(160deg, #0f8a66 0%, #0a684d 100%);
            color: #ffffff;
            border-color: transparent;
        }

        .metric-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            opacity: 0.78;
        }

        .metric-value {
            margin-top: 10px;
            font-size: 28px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .metric-note {
            margin-top: 8px;
            font-size: 13px;
            line-height: 1.4;
            color: inherit;
            opacity: 0.86;
        }

        .meta {
            display: grid;
            gap: 10px;
            margin-top: 16px;
        }

        .meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--line);
            font-size: 13px;
        }

        .meta-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .meta-label {
            color: var(--muted);
        }

        .meta-value {
            font-weight: 700;
            text-align: right;
            word-break: break-word;
        }

        .section {
            padding: 18px;
        }

        .section-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 14px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .section-note {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .reward-list {
            display: grid;
            gap: 12px;
        }

        .reward {
            padding: 16px;
            border-radius: 22px;
            border: 1px solid var(--line);
            background:
                linear-gradient(180deg, rgba(255,255,255,0.86), rgba(255,255,255,0.7));
        }

        .reward-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .reward-tag {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--secondary);
            background: rgba(18, 43, 69, 0.08);
        }

        .reward-value {
            font-size: 16px;
            font-weight: 800;
        }

        .reward p {
            margin: 10px 0 14px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.45;
        }

        .reward-actions {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: center;
        }

        .reward-meta {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }

        button {
            appearance: none;
            border: 0;
            cursor: pointer;
            font: inherit;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 16px;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: -0.01em;
            transition: transform 120ms ease, opacity 120ms ease;
        }

        .btn:active {
            transform: scale(0.985);
        }

        .btn-primary {
            background: linear-gradient(160deg, var(--primary) 0%, var(--primary-deep) 100%);
            color: #ffffff;
        }

        .btn-secondary {
            background: linear-gradient(160deg, var(--secondary) 0%, var(--secondary-deep) 100%);
            color: #ffffff;
        }

        .btn-accent {
            background: linear-gradient(160deg, #e2b04d 0%, #c98718 100%);
            color: #211505;
        }

        .footer-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .ghost {
            min-height: 48px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.72);
            color: var(--secondary);
            font-size: 14px;
            font-weight: 800;
        }

        .brand-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px 18px;
        }

        .platform-logo {
            height: 26px;
            width: auto;
            display: block;
        }

        .brand-copy {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
            text-align: right;
        }

        .debug {
            display: none;
            margin-top: 14px;
            padding: 14px;
            border-radius: 18px;
            background: #0c1117;
            color: #e4edf5;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            line-height: 1.5;
            overflow: auto;
            white-space: pre-wrap;
        }

        .debug.visible {
            display: block;
        }

        @media (max-width: 420px) {
            .metrics,
            .footer-actions {
                grid-template-columns: 1fr;
            }

            .reward-actions {
                grid-template-columns: 1fr;
            }

            .brand-strip {
                flex-direction: column;
                align-items: flex-start;
            }

            .brand-copy {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="hero">
            <div class="brand-row">
                <img class="tryb-logo" src="/branding/tryb-logo-white.png" alt="TRYB Loyalty">
                <div class="badge">TRYB x Zelty POS</div>
            </div>
            <h1>Reward Actions</h1>
            <p>
                Open rewards directly from the POS, identify the active customer from Zelty,
                and prepare the next redemption flow without leaving the checkout screen.
            </p>
        </section>

        <section class="stack">
            <section class="panel summary">
                <div class="summary-top">
                    <div>
                        <div class="eyebrow">Active customer</div>
                        <div id="customer-name" class="customer-name">Waiting for Zelty</div>
                    </div>
                    <div id="status-pill" class="status-pill">
                        <span class="dot"></span>
                        <span id="status-text">Connecting</span>
                    </div>
                </div>

                <div class="metrics">
                    <div class="metric primary">
                        <div class="metric-label">Points balance</div>
                        <div id="points-balance" class="metric-value">1,240</div>
                        <div class="metric-note">Placeholder preview until TRYB profile data is connected.</div>
                    </div>
                    <div class="metric">
                        <div class="metric-label">Rewards ready</div>
                        <div id="rewards-count" class="metric-value">3</div>
                        <div class="metric-note">Fast actions for cashier-assisted redemption.</div>
                    </div>
                </div>

                <div class="meta">
                    <div class="meta-row">
                        <div class="meta-label">SDK version</div>
                        <div id="sdk-version" class="meta-value">Unknown</div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-label">Order ID</div>
                        <div id="order-id" class="meta-value">Unknown</div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-label">Restaurant</div>
                        <div id="restaurant-id" class="meta-value">Unknown</div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-label">Contact</div>
                        <div id="customer-contact" class="meta-value">Unknown</div>
                    </div>
                </div>

                <div id="debug" class="debug"></div>
            </section>

            <section class="panel section">
                <div class="section-head">
                    <div class="section-title">Available rewards</div>
                    <div class="section-note">Preview state</div>
                </div>

                <div class="reward-list">
                    <article class="reward">
                        <div class="reward-top">
                            <div class="reward-tag">Hot drink</div>
                            <div class="reward-value">Free Coffee</div>
                        </div>
                        <p>Apply this reward when the customer wants to redeem their earned coffee perk.</p>
                        <div class="reward-actions">
                            <div class="reward-meta">Estimated cost: 120 pts</div>
                            <button class="btn btn-primary" data-action="apply_free_coffee">Apply reward</button>
                        </div>
                    </article>

                    <article class="reward">
                        <div class="reward-top">
                            <div class="reward-tag">Dessert</div>
                            <div class="reward-value">10% Off Dessert</div>
                        </div>
                        <p>Use this action to reward repeat customers during the same POS flow.</p>
                        <div class="reward-actions">
                            <div class="reward-meta">Available for selected pastries and desserts</div>
                            <button class="btn btn-secondary" data-action="apply_dessert_discount">Apply reward</button>
                        </div>
                    </article>

                    <article class="reward">
                        <div class="reward-top">
                            <div class="reward-tag">Birthday</div>
                            <div class="reward-value">Birthday Treat</div>
                        </div>
                        <p>Open the reward details first, then confirm redemption when the customer is ready.</p>
                        <div class="reward-actions">
                            <div class="reward-meta">Manual review before redemption</div>
                            <button class="btn btn-accent" data-action="view_birthday_treat">View details</button>
                        </div>
                    </article>
                </div>
            </section>

            <section class="panel section">
                <div class="footer-actions">
                    <button id="show-card" class="ghost">Show loyalty card</button>
                    <button id="close-view" class="ghost">Close</button>
                </div>
            </section>

            <section class="panel brand-strip">
                <img class="platform-logo" src="/branding/tryb-platform-dark.png" alt="TRYB Loyalty platform">
                <div class="brand-copy">
                    Reward actions preview for the Zelty POS WebView.
                    Live TRYB customer data is the next integration step.
                </div>
            </section>
        </section>
    </main>

    <script>
        (function () {
            const els = {
                statusPill: document.getElementById('status-pill'),
                statusText: document.getElementById('status-text'),
                customerName: document.getElementById('customer-name'),
                pointsBalance: document.getElementById('points-balance'),
                rewardsCount: document.getElementById('rewards-count'),
                sdkVersion: document.getElementById('sdk-version'),
                orderId: document.getElementById('order-id'),
                restaurantId: document.getElementById('restaurant-id'),
                customerContact: document.getElementById('customer-contact'),
                debug: document.getElementById('debug'),
                closeView: document.getElementById('close-view'),
                showCard: document.getElementById('show-card')
            };

            const state = {
                version: null,
                order: null,
                lastAction: null
            };

            function safeText(value, fallback) {
                if (value === undefined || value === null || value === '') {
                    return fallback;
                }

                if (typeof value === 'object') {
                    try {
                        return JSON.stringify(value);
                    } catch (error) {
                        return fallback;
                    }
                }

                return String(value);
            }

            function displayName(customer) {
                if (!customer) {
                    return 'Waiting for Zelty';
                }

                return safeText(
                    customer.nice_name || [customer.fname, customer.name].filter(Boolean).join(' '),
                    'Unknown customer'
                );
            }

            function displayContact(customer) {
                if (!customer) {
                    return 'Unknown';
                }

                return safeText(customer.mail || customer.phone, 'Unknown');
            }

            function render() {
                const order = state.order || {};
                const customer = order.customer || null;

                if (state.order) {
                    els.statusPill.classList.add('ready');
                    els.statusText.textContent = 'Connected';
                } else {
                    els.statusPill.classList.remove('ready');
                    els.statusText.textContent = 'Connecting';
                }

                els.customerName.textContent = displayName(customer);
                els.sdkVersion.textContent = safeText(state.version, 'Unknown');
                els.orderId.textContent = safeText(order.id, 'Unknown');
                els.restaurantId.textContent = safeText(order.restaurant_id, 'Unknown');
                els.customerContact.textContent = displayContact(customer);
                els.pointsBalance.textContent = customer ? '1,240' : '--';
                els.rewardsCount.textContent = customer ? '3' : '--';

                els.debug.textContent = JSON.stringify({
                    version: state.version,
                    order: state.order,
                    lastAction: state.lastAction
                }, null, 2);
                els.debug.classList.add('visible');
            }

            function dispatchToZelty(data) {
                if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.zeltyLoyaltyHandler) {
                    window.webkit.messageHandlers.zeltyLoyaltyHandler.postMessage(data);
                    return true;
                }

                state.lastAction = {
                    error: 'webkit_not_found',
                    attempted: data
                };
                render();
                return false;
            }

            function handleRewardAction(action) {
                state.lastAction = {
                    event: 'reward_action_preview',
                    action: action
                };
                render();
            }

            window.zeltySetOrder = function (version, order) {
                state.order = order || null;
                if (version !== undefined && version !== null) {
                    state.version = version;
                }
                render();
            };

            window.zeltySetVersion = function (version) {
                state.version = version;
                render();
            };

            window.zeltyHandleFunction = function (data) {
                state.lastAction = data || null;
                render();
            };

            function requestBootstrapData() {
                dispatchToZelty({
                    event: 'get_order',
                    version: 1
                });

                dispatchToZelty({
                    event: 'get_version',
                    version: 1
                });
            }

            document.querySelectorAll('[data-action]').forEach(function (button) {
                button.addEventListener('click', function () {
                    handleRewardAction(button.getAttribute('data-action'));
                });
            });

            els.showCard.addEventListener('click', function () {
                handleRewardAction('show_loyalty_card');
            });

            els.closeView.addEventListener('click', function () {
                dispatchToZelty({
                    event: 'dismiss',
                    version: 1
                });
            });

            requestBootstrapData();
            render();
        })();
    </script>
</body>
</html>
HTML;

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
