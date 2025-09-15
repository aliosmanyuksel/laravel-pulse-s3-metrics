# Laravel 12 Projenize Entegrasyon Rehberi

## 1. Paketi Projenize Ekleyin

### Composer ile Yerel Paket Olarak Ekleme

`composer.json` dosyanızda `repositories` bölümüne şunu ekleyin:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/Users/aliosmanyuksel/Documents/GitHub/aqtivite/laravel-pulse-s3-metrics-l12"
        }
    ],
    "require": {
        "aliosmanyuksel/laravel-pulse-s3-metrics": "*"
    }
}
```

Sonra şu komutu çalıştırın:
```bash
composer require aliosmanyuksel/laravel-pulse-s3-metrics
```

## 2. Konfigürasyonu Yayınlayın

```bash
php artisan vendor:publish --tag="laravel-pulse-s3-metrics-config"
```

Bu komut `config/pulse-s3-metrics.php` dosyasını oluşturacak.

## 3. Environment Değişkenlerini Ekleyin

`.env` dosyanıza şunları ekleyin:

```env
# S3 Metrics için gerekli değişkenler
PULSE_S3_METRICS_ENABLED=true
AWS_BUCKET=your-bucket-name
AWS_STORAGE_CLASS=StandardStorage
AWS_ACCESS_KEY_ID=your-access-key
AWS_SECRET_ACCESS_KEY=your-secret-key
AWS_DEFAULT_REGION=us-east-1
```

## 4. Pulse Konfigürasyonunu Güncelleyin

`config/pulse.php` dosyasının `recorders` bölümüne şunu ekleyin:

```php
'recorders' => [
    // Mevcut recorder'larınız...
    
    \AliOsmanYuksel\PulseS3Metrics\Recorders\S3Metrics::class => [
        'enabled' => env('PULSE_S3_METRICS_ENABLED', true),
    ],
],
```

## 5. Dashboard'a Kartı Ekleyin

`resources/views/vendor/pulse/dashboard.blade.php` dosyasına şunu ekleyin:

```blade
<x-pulse>
    {{-- S3 Metrics kartı - en iyi tam genişlikte görünür --}}
    <livewire:pulse-s3-metrics cols="full" />
    
    {{-- Diğer kartlarınız... --}}
</x-pulse>
```

## 6. Pulse Worker'ı Başlatın

```bash
php artisan pulse:check
```

## 7. Test Edin

Pulse dashboard'unuzu ziyaret edin ve S3 metriklerinizi görün. İlk veriler 24 saat içinde görünmeye başlayacaktır.

## GitHub'a Yüklemek İçin

### 1. Yeni Repository Oluşturun

GitHub'da yeni bir repository oluşturun: `laravel-pulse-s3-metrics`

### 2. Git İşlemlerini Yapın

```bash
cd /Users/aliosmanyuksel/Documents/GitHub/aqtivite/laravel-pulse-s3-metrics-l12
git init
git add .
git commit -m "Initial commit: Laravel 12 compatible S3 Metrics for Pulse"
git branch -M main
git remote add origin https://github.com/aliosmanyuksel/laravel-pulse-s3-metrics.git
git push -u origin main
```

### 3. Packagist'e Yükleyin (İsteğe Bağlı)

1. [Packagist.org](https://packagist.org)'a gidin
2. "Submit" butonuna tıklayın
3. Repository URL'nizi girin
4. Paketi doğrulayın ve yayınlayın

## Sorun Giderme

### S3 Metrikleri Görünmüyor

- AWS CloudWatch'ta S3 metriklerinin aktif olduğundan emin olun
- AWS credentials'larınızın doğru olduğunu kontrol edin
- Bucket adının doğru olduğundan emin olun
- Region'ın doğru olduğundan emin olun

### Composer Hatası

- PHP versiyonunuzun 8.4+ olduğundan emin olun
- Laravel 12 kullandığınızdan emin olun
- Composer cache'ini temizleyin: `composer clear-cache`

## Özelleştirme

### Çoklu Bucket Desteği

Birden fazla S3 bucket'ı izlemek için config dosyasını düzenleyebilirsiniz.

### Farklı Storage Class'lar

Farklı storage class'ları (IA, Glacier, etc.) için ayrı kartlar oluşturabilirsiniz.
