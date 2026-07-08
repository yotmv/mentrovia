<?php

namespace Database\Seeders;

use App\Enums\ArticleStatus;
use App\Enums\RiskLevel;
use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Seeds knowledge articles from markdown files with YAML front matter in
 * database/seeders/knowledge/. Idempotent: articles are matched by slug and
 * updated in place; sources are recreated on every run.
 */
class KnowledgeArticleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (glob(database_path('seeders/knowledge/*.md')) ?: [] as $path) {
            $this->seedArticle($path);
        }
    }

    private function seedArticle(string $path): void
    {
        [$meta, $body] = $this->parse($path);

        $riskLevel = RiskLevel::from($meta['risk_level']);

        // Symfony YAML parses unquoted dates to Unix timestamp integers.
        $verifiedAt = is_int($meta['verified_at'])
            ? Carbon::createFromTimestampUTC($meta['verified_at'])
            : Carbon::parse($meta['verified_at']);

        $article = KnowledgeArticle::updateOrCreate(
            ['slug' => $meta['slug']],
            [
                'title' => $meta['title'],
                'jurisdiction' => $meta['jurisdiction'] ?? 'TX',
                'category' => $meta['category'],
                'body_markdown' => $body,
                'source_summary' => $meta['source_summary'] ?? null,
                'risk_level' => $riskLevel,
                'last_verified_at' => $verifiedAt,
                'next_review_at' => $verifiedAt->copy()->addDays($riskLevel->reviewIntervalDays()),
                'status' => ArticleStatus::Published,
                'version' => 1,
            ],
        );

        $article->sources()->delete();

        foreach ($meta['sources'] as $source) {
            $article->sources()->create([
                'source_name' => $source['name'],
                'source_url' => $source['url'],
                'source_type' => $source['type'],
                'retrieved_at' => $verifiedAt,
                'notes' => $source['notes'] ?? null,
            ]);
        }
    }

    /**
     * Split a markdown file into parsed YAML front matter and body.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function parse(string $path): array
    {
        $contents = (string) file_get_contents($path);

        if (preg_match('/\A---\n(.+?)\n---\n(.*)\z/s', $contents, $matches) !== 1) {
            throw new RuntimeException("Knowledge article {$path} is missing YAML front matter.");
        }

        /** @var array<string, mixed> $meta */
        $meta = Yaml::parse($matches[1]);

        return [$meta, trim($matches[2])];
    }
}
