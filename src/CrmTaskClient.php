<?php

declare(strict_types=1);

namespace skeeks\cms\ai\service;

final class CrmTaskClient
{
    private string $endpoint;

    /**
     * The installation configuration selects the target. The caller supplies
     * an OAuth access token issued by this target for the requesting employee.
     */
    public function __construct(string $resource, private string $accessToken)
    {
        $resource = rtrim($resource, '/');
        if (!preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]+)?/cms/rest-api\z~i', $resource)) {
            throw new \InvalidArgumentException('Некорректный HTTPS-адрес REST API CRM.');
        }
        if (!preg_match('~\A[A-Za-z0-9._+\-/]+=*\z~', $accessToken)) {
            throw new \InvalidArgumentException('OAuth-токен CRM недоступен.');
        }
        $this->endpoint = $resource . '/tools/';
    }

    /**
     * Find visible records. Keep their IDs and names server-side until the
     * employee selects an option. A broad result must be refined in the chat.
     */
    public function candidates(string $companyQuery, string $serviceQuery): array
    {
        $companyQuery = trim($companyQuery);
        $serviceQuery = trim($serviceQuery);
        if (($companyQuery === '' && $serviceQuery === '') ||
            mb_strlen($companyQuery) > 255 || mb_strlen($serviceQuery) > 255) {
            throw new \InvalidArgumentException('Уточните компанию или услугу.');
        }

        $companies = $companyQuery === '' ? [] : $this->lookup('cms_company', $companyQuery);
        if ($companyQuery !== '' && !$companies) {
            return [];
        }
        if ($serviceQuery === '') {
            return array_map(static fn(array $company) => [
                'company_id' => (int)$company['id'],
                'service_id' => 0,
                'label' => $company['name'],
            ], $companies);
        }

        $services = $this->lookup('cms_deal', $serviceQuery);
        $companyNames = array_column($companies, 'name', 'id');
        $options = [];
        foreach ($services as $service) {
            $companyId = (int)($service['cms_company_id'] ?? 0);
            if ($companyQuery !== '' && !isset($companyNames[$companyId])) {
                continue;
            }
            if ($companyId && !isset($companyNames[$companyId])) {
                $companyNames[$companyId] = $this->request('cms_company_get', ['id' => $companyId])['name'];
            }
            $companyName = $companyNames[$companyId] ?? '';
            $options[] = [
                'company_id' => $companyId,
                'service_id' => (int)$service['id'],
                'label' => $service['name'] . ($companyName === '' ? '' : ' · ' . $companyName),
            ];
        }
        return $options;
    }

    /** Submit the previously checked data and the stable chat message key. */
    public function create(string $requestKey, string $title, string $description, int $companyId, int $serviceId): array
    {
        return $this->request('cms_ai_task_create', [
            'request_key' => $requestKey,
            'title' => $title,
            'description' => $description,
            'company_id' => $companyId,
            'service_id' => $serviceId,
        ]);
    }

    private function lookup(string $type, string $query): array
    {
        if (preg_match('/\A#([1-9][0-9]{0,9})\z/', $query, $match)) {
            return [$this->request($type . '_get', ['id' => (int)$match[1]])];
        }

        $page = $this->request($type . '_list', ['q' => $query, 'limit' => 10]);
        if ((int)$page['total'] > 10) {
            throw new \DomainException('Найдено больше десяти вариантов. Уточните название или укажите номер карточки.');
        }
        return $page['items'];
    }

    private function request(string $tool, array $arguments): array
    {
        $handle = curl_init($this->endpoint . $tool);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($arguments, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        try {
            $body = curl_exec($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($handle);
        }

        if ($status === 401 || $status === 403) {
            throw new \DomainException('CRM не подтвердила OAuth-доступ сотрудника к этой операции.');
        }
        if ($status >= 400 && $status < 500) {
            throw new \DomainException('CRM отклонила запрос. Проверьте выбранную карточку и доступные операции.');
        }
        if (!is_string($body) || $status !== 200) {
            throw new \RuntimeException('Ответ CRM не получен. Повторите с тем же номером поручения.');
        }
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (($response['success'] ?? false) !== true || !is_array($response['data'] ?? null)) {
            throw new \RuntimeException('Некорректный ответ REST API CRM.');
        }
        return $response['data'];
    }
}
