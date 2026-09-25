{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach($paths as $path)
  <url>
    <loc>{{ url($path) }}</loc>
    <xhtml:link rel="alternate" hreflang="en" href="{{ url($path) }}?lang=en"/>
    <xhtml:link rel="alternate" hreflang="fr" href="{{ url($path) }}?lang=fr"/>
    <changefreq>{{ in_array($path, ['/', '/providers'], true) ? 'weekly' : 'monthly' }}</changefreq>
  </url>
@endforeach
</urlset>
