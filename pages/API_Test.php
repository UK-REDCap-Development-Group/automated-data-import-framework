<?php
/*
 * Simple page for testing API pulls manually, not visible in production.
 */

/** @var \UKModules\ADIF\ADIF $module */

$sources = $module->getApiSources();

$selectedIndex = isset($_POST['source_index']) ? $_POST['source_index'] : (string) (!empty($sources) ? $sources[0]['index'] : '');
$method = isset($_POST['method']) ? strtoupper($_POST['method']) : 'GET';
$path = isset($_POST['path']) ? $_POST['path'] : '';
$payloadRaw = isset($_POST['payload']) ? $_POST['payload'] : '';

$result = null;
$formError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = [];

    if (trim($payloadRaw) !== '') {
        $payload = json_decode($payloadRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $formError = 'The request body is not valid JSON: ' . json_last_error_msg();
        }
    }

    if ($formError === null) {
        $sourceExists = false;
        foreach ($sources as $source) {
            if ((string) $source['index'] === (string) $selectedIndex) {
                $sourceExists = true;
                break;
            }
        }

        if (!$sourceExists) {
            $formError = 'Please select a configured API source.';
        } else {
            try {
                $result = $module->dispatchApiRequest($selectedIndex, $path, $method, $payload ?: []);
            } catch (\Exception $e) {
                $formError = $e->getMessage();
            }
        }
    }
}

function adif_status_class($statusCode)
{
    if ($statusCode === null) {
        return 'adif-status-none';
    }
    if ($statusCode >= 200 && $statusCode < 300) {
        return 'adif-status-ok';
    }
    if ($statusCode >= 300 && $statusCode < 400) {
        return 'adif-status-redirect';
    }
    return 'adif-status-error';
}

function adif_flatten_headers($headers)
{
    $flat = [];
    foreach ((array) $headers as $name => $values) {
        $flat[$name] = implode(', ', (array) $values);
    }
    return $flat;
}
?>

<link rel="stylesheet" href="<?= $module->getUrl('css/test_page.css') ?>">

<div class="adif-test-page">
    <h3>ADIF API Test</h3>
    <p>Send a request through a configured API source and inspect exactly what comes back &mdash; status, headers,
        timing, and body. This page is for manual testing only.</p>

    <?php if (empty($sources)): ?>
        <div class="adif-alert adif-alert-empty">
            No API sources are configured for this project yet. Add one under the module's project configuration
            ("Input the base URLs of any APIs which you would like to make available to your project").
        </div>
    <?php else: ?>

        <?php if ($formError): ?>
            <div class="adif-alert adif-alert-error"><?= htmlspecialchars($formError) ?></div>
        <?php endif; ?>

        <form method="post">
            <div class="adif-form-row">
                <div class="adif-form-field">
                    <label for="adif-source">API Source</label>
                    <select name="source_index" id="adif-source">
                        <?php foreach ($sources as $source): ?>
                            <option value="<?= htmlspecialchars($source['index']) ?>" <?= ((string) $source['index'] === (string) $selectedIndex) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($source['url']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adif-form-field adif-method">
                    <label for="adif-method">Method</label>
                    <select name="method" id="adif-method">
                        <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m): ?>
                            <option value="<?= $m ?>" <?= ($method === $m) ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adif-form-field adif-path">
                    <label for="adif-path">Endpoint path (appended to the source's base URL)</label>
                    <input type="text" name="path" id="adif-path" placeholder="/example/endpoint" value="<?= htmlspecialchars($path) ?>">
                </div>
            </div>
            <div class="adif-form-row adif-payload">
                <div class="adif-form-field" style="flex: 1 1 100%;">
                    <label for="adif-payload">Request body (JSON, used for POST / PUT / PATCH)</label>
                    <textarea name="payload" id="adif-payload" placeholder='{ "example": "value" }'><?= htmlspecialchars($payloadRaw) ?></textarea>
                </div>
            </div>
            <button type="submit" class="adif-submit btn btn-primaryrc">Send Request</button>
        </form>

        <?php if ($result !== null): ?>
            <h4>Response</h4>
            <div class="adif-meta">
                <span class="adif-status-pill <?= adif_status_class($result['statusCode']) ?>">
                    <?= $result['statusCode'] ? htmlspecialchars($result['statusCode'] . ' ' . $result['reasonPhrase']) : 'No response' ?>
                </span>
                <span><span class="adif-meta-label">Time:</span><?= htmlspecialchars($result['elapsedMs'] !== null ? $result['elapsedMs'] . ' ms' : 'n/a') ?></span>
                <span><span class="adif-meta-label">Size:</span><?= htmlspecialchars($result['byteSize'] !== null ? $result['byteSize'] . ' bytes' : 'n/a') ?></span>
                <span><span class="adif-meta-label">URL:</span><?= htmlspecialchars($result['requestMethod'] . ' ' . $result['requestUrl']) ?></span>
            </div>

            <?php if ($result['error']): ?>
                <div class="adif-alert adif-alert-error">
                    <strong>Request error:</strong> <?= htmlspecialchars($result['error']) ?>
                </div>
            <?php endif; ?>

            <div class="adif-tabs">
                <button type="button" class="adif-tab-btn active" data-tab="body-pretty">Body (formatted)</button>
                <button type="button" class="adif-tab-btn" data-tab="body-raw">Body (raw)</button>
                <button type="button" class="adif-tab-btn" data-tab="headers">Response Headers</button>
                <button type="button" class="adif-tab-btn" data-tab="request">Request Sent</button>
            </div>

            <div class="adif-tab-panel active" data-tab="body-pretty">
                <?php if ($result['bodyJson'] !== null): ?>
                    <pre class="adif-body-pre"><?= htmlspecialchars(json_encode($result['bodyJson'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php elseif ($result['body'] !== null && $result['body'] !== ''): ?>
                    <pre class="adif-body-pre"><?= htmlspecialchars($result['body']) ?></pre>
                <?php else: ?>
                    <p><em>Empty response body.</em></p>
                <?php endif; ?>
            </div>

            <div class="adif-tab-panel" data-tab="body-raw">
                <?php if ($result['body'] !== null && $result['body'] !== ''): ?>
                    <pre class="adif-body-pre"><?= htmlspecialchars($result['body']) ?></pre>
                <?php else: ?>
                    <p><em>Empty response body.</em></p>
                <?php endif; ?>
            </div>

            <div class="adif-tab-panel" data-tab="headers">
                <?php $responseHeaders = adif_flatten_headers($result['responseHeaders']); ?>
                <?php if (empty($responseHeaders)): ?>
                    <p><em>No response headers were captured.</em></p>
                <?php else: ?>
                    <table class="adif-headers-table">
                        <?php foreach ($responseHeaders as $name => $value): ?>
                            <tr>
                                <th><?= htmlspecialchars($name) ?></th>
                                <td><?= htmlspecialchars($value) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>

            <div class="adif-tab-panel" data-tab="request">
                <table class="adif-headers-table">
                    <tr><th>Method</th><td><?= htmlspecialchars($result['requestMethod']) ?></td></tr>
                    <tr><th>URL</th><td><?= htmlspecialchars($result['requestUrl']) ?></td></tr>
                    <?php foreach (adif_flatten_headers($result['requestHeaders']) as $name => $value): ?>
                        <tr>
                            <th><?= htmlspecialchars($name) ?></th>
                            <td><?= htmlspecialchars(stripos($name, 'authorization') === 0 ? 'Bearer ••••••••' : $value) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
    (function () {
        var buttons = document.querySelectorAll('.adif-tab-btn');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tab = btn.getAttribute('data-tab');
                document.querySelectorAll('.adif-tab-btn').forEach(function (b) { b.classList.remove('active'); });
                document.querySelectorAll('.adif-tab-panel').forEach(function (p) { p.classList.remove('active'); });
                btn.classList.add('active');
                document.querySelector('.adif-tab-panel[data-tab="' + tab + '"]').classList.add('active');
            });
        });
    })();
</script>
