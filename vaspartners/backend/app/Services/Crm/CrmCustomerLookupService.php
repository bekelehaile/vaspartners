<?php

namespace App\Services\Crm;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BSS GetCustomer by service number — same contract as fixedservices
 * QueryCustomerByServiceNumberService (richer KYC than bill_complaint IVR).
 */
class CrmCustomerLookupService
{
    public function enabled(): bool
    {
        return (bool) config('services.crm.enabled', true)
            && filled(config('services.crm.endpoint'))
            && filled(config('services.crm.access_user'))
            && filled(config('services.crm.access_pwd'));
    }

    /**
     * @return array{
     *   found: bool,
     *   customer_name: ?string,
     *   phone: ?string,
     *   email: ?string,
     *   gender: ?string,
     *   nationality: ?string,
     *   birthdate: ?string,
     *   identification_type: ?string,
     *   identification_number: ?string,
     *   customer_code: ?string,
     *   raw: array<string, mixed>
     * }|null  null when CRM disabled / unavailable
     */
    public function lookupByPhone(string $phone): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $normalized = PhoneNumber::normalize($phone);
        if ($normalized === '' || ! PhoneNumber::isValidEthioTelecomMobile($normalized)) {
            return $this->emptyResult();
        }

        $timeout = max(5, (int) config('services.crm.timeout', 15));
        $endpoint = (string) config('services.crm.endpoint');
        $xml = $this->buildRequestXml($normalized);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(5)
                ->retry(2, 200, throw: false)
                ->withOptions(['verify' => false])
                ->withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
                ->withBody($xml, 'text/xml')
                ->post($endpoint);

            if (! $response->successful()) {
                Log::warning('CRM GetCustomer HTTP error', [
                    'status' => $response->status(),
                    'phone_masked' => str_repeat('*', max(0, strlen($normalized) - 4)).substr($normalized, -4),
                ]);

                return null;
            }

            return $this->parseResponseXml($response->body(), $normalized);
        } catch (Throwable $e) {
            Log::error('CRM GetCustomer exception', ['message' => $e->getMessage()]);

            return null;
        }
    }

    protected function buildRequestXml(string $serviceNumber): string
    {
        $transactionId = uniqid();
        $processTime = now()->format('YmdHis');
        $xml = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $language = $xml((string) config('services.crm.language', '2002'));
        $channelId = $xml((string) config('services.crm.channel_id', '116'));
        $technicalChannelId = $xml((string) config('services.crm.technical_channel_id', '53'));
        $accessUser = $xml((string) config('services.crm.access_user'));
        $accessPwd = $xml((string) config('services.crm.access_pwd'));
        $serviceNumber = $xml($serviceNumber);

        return <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
 xmlns:ser="http://oss.huawei.com/webservice/bss/services"
 xmlns:com="http://www.huawei.com/bss/soaif/interface/common/">
   <soapenv:Header/>
   <soapenv:Body>
      <ser:GetCustomerRequest>
         <ser:RequestHeader>
            <com:Version>1</com:Version>
            <com:TransactionId>{$transactionId}</com:TransactionId>
            <com:ProcessTime>{$processTime}</com:ProcessTime>
            <com:Language>{$language}</com:Language>
            <com:ChannelId>{$channelId}</com:ChannelId>
            <com:TechnicalChannelId>{$technicalChannelId}</com:TechnicalChannelId>
            <com:AccessUser>{$accessUser}</com:AccessUser>
            <com:AccessPwd>{$accessPwd}</com:AccessPwd>
         </ser:RequestHeader>
         <ser:GetCustomerBody>
            <com:ServiceNumber>{$serviceNumber}</com:ServiceNumber>
         </ser:GetCustomerBody>
      </ser:GetCustomerRequest>
   </soapenv:Body>
</soapenv:Envelope>
XML;
    }

    /**
     * @return array{
     *   found: bool,
     *   customer_name: ?string,
     *   phone: ?string,
     *   email: ?string,
     *   gender: ?string,
     *   nationality: ?string,
     *   birthdate: ?string,
     *   identification_type: ?string,
     *   identification_number: ?string,
     *   customer_code: ?string,
     *   raw: array<string, mixed>
     * }
     */
    protected function parseResponseXml(string $xml, string $queriedPhone): array
    {
        libxml_use_internal_errors(true);
        $xmlObject = simplexml_load_string($xml);
        if ($xmlObject === false) {
            Log::warning('CRM GetCustomer: invalid XML');

            return $this->emptyResult();
        }

        $namespaces = $xmlObject->getNamespaces(true);
        $soapNs = $namespaces['soapenv'] ?? 'http://schemas.xmlsoap.org/soap/envelope/';
        $serNs = $namespaces['ser'] ?? 'http://oss.huawei.com/webservice/bss/services';
        $comNs = $namespaces['com'] ?? 'http://www.huawei.com/bss/soaif/interface/common/';

        $body = $xmlObject->children($soapNs)->Body ?? null;
        $response = $body?->children($serNs)->GetCustomerResponse ?? null;
        if ($response === null) {
            return $this->emptyResult();
        }

        $header = $response->ResponseHeader->children($comNs);
        $custBody = $response->GetCustomerBody->children($comNs);
        $retCode = (string) ($header->RetCode ?? '');

        if ($retCode !== '0') {
            return $this->emptyResult([
                'ret_code' => $retCode,
                'ret_msg' => (string) ($header->RetMsg ?? ''),
            ]);
        }

        $first = trim((string) ($custBody->FirstName ?? ''));
        $middle = trim((string) ($custBody->MiddleName ?? ''));
        $last = trim((string) ($custBody->LastName ?? ''));
        $name = trim(implode(' ', array_filter([$first, $middle, $last], fn ($p) => $p !== '')));

        $genderCode = (string) ($custBody->Gender ?? '');
        $nationalityCode = (string) ($custBody->Nationality ?? '');
        $certificateType = (string) ($custBody->CertificateType ?? '');
        $certificateNo = trim((string) ($custBody->CertificateNumber ?? ''));
        $dob = trim((string) ($custBody->DateOfBirth ?? ''));

        $extParams = [];
        foreach ($custBody->ExtParamList->ParameterInfo ?? [] as $param) {
            $paramName = (string) ($param->ParamName ?? '');
            if ($paramName !== '') {
                $extParams[$paramName] = (string) ($param->ParamValue ?? '');
            }
        }

        $contacts = [];
        foreach ($custBody->ContactList->ContactInfo ?? [] as $contact) {
            $contacts[] = [
                'name1' => (string) ($contact->Relaname1 ?? ''),
                'name2' => (string) ($contact->Relaname2 ?? ''),
                'mobile' => (string) ($contact->Relatel1 ?? ''),
                'fax' => (string) ($contact->Relafax ?? ''),
            ];
        }

        $addresses = [];
        foreach ($custBody->AddressList->AddressInfo ?? [] as $address) {
            $addresses[] = [
                'address1' => (string) ($address->Address1 ?? ''),
                'address2' => (string) ($address->Address2 ?? ''),
                'address3' => (string) ($address->Address3 ?? ''),
                'address4' => (string) ($address->Address4 ?? ''),
                'address5' => (string) ($address->Address5 ?? ''),
                'address6' => (string) ($address->Address6 ?? ''),
            ];
        }

        $raw = [
            'customer_id' => (string) ($custBody->CustomerId ?? ''),
            'customer_code' => (string) ($custBody->CustomerCode ?? ''),
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'gender_code' => $genderCode,
            'nationality_code' => $nationalityCode,
            'certificate_type' => $certificateType,
            'certificate_number' => $certificateNo,
            'date_of_birth' => $dob,
            'status' => (string) ($custBody->Status ?? ''),
            'contacts' => $contacts,
            'addresses' => $addresses,
            'ext_params' => $extParams,
        ];

        return [
            'found' => $name !== '',
            'customer_name' => $name !== '' ? $name : null,
            'phone' => $queriedPhone,
            'email' => null,
            'gender' => $this->mapGender($genderCode),
            'nationality' => $this->mapNationality($nationalityCode),
            'birthdate' => $this->mapBirthdate($dob),
            'identification_type' => $this->mapCertificateType($certificateType),
            'identification_number' => $certificateNo !== '' ? $certificateNo : null,
            'customer_code' => $raw['customer_code'] !== '' ? $raw['customer_code'] : null,
            'raw' => $raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{
     *   found: bool,
     *   customer_name: ?string,
     *   phone: ?string,
     *   email: ?string,
     *   gender: ?string,
     *   nationality: ?string,
     *   birthdate: ?string,
     *   identification_type: ?string,
     *   identification_number: ?string,
     *   customer_code: ?string,
     *   raw: array<string, mixed>
     * }
     */
    protected function emptyResult(array $raw = []): array
    {
        return [
            'found' => false,
            'customer_name' => null,
            'phone' => null,
            'email' => null,
            'gender' => null,
            'nationality' => null,
            'birthdate' => null,
            'identification_type' => null,
            'identification_number' => null,
            'customer_code' => null,
            'raw' => $raw,
        ];
    }

    protected function mapGender(string $code): ?string
    {
        return match ($code) {
            '1' => 'Male',
            '2' => 'Female',
            default => $code !== '' ? $code : null,
        };
    }

    protected function mapNationality(string $code): ?string
    {
        return match ($code) {
            '1231' => 'Ethiopia',
            '1000' => 'Other',
            default => $code !== '' ? $code : null,
        };
    }

    protected function mapCertificateType(string $code): ?string
    {
        return match ($code) {
            '1' => 'Passport',
            '2' => 'National ID',
            default => $code !== '' ? $code : null,
        };
    }

    protected function mapBirthdate(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        // BSS may return Ymd or Y-m-d.
        if (preg_match('/^\d{8}$/', $raw) === 1) {
            return substr($raw, 0, 4).'-'.substr($raw, 4, 2).'-'.substr($raw, 6, 2);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw) === 1) {
            return substr($raw, 0, 10);
        }

        return $raw;
    }
}
