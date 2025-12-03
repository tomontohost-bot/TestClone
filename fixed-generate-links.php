private function generate_links($tmdb_id, $type, $s = null, $e = null) {
    if (!preg_match('/^\d+$/', $tmdb_id)) return [];
    
    $autoplay = '?autoPlay=true';
    
    if ($type === 'tv') {
        $season_episode = "{$s}-{$e}";
        return [
            "MappleTV" => "https://mappletv.uk/watch/tv/{$tmdb_id}-{$season_episode}{$autoplay}",
            "VidLink" => "https://vidlink.pro/tv/{$tmdb_id}/{$s}/{$e}",
            "PrimeWire" => "https://www.primewire.tf/embed/tv?tmdb={$tmdb_id}&season={$s}&episode={$e}",
            "Embed SU" => "https://embed.su/embed/tv/{$tmdb_id}/{$s}/{$e}",
            "MultiEmbed" => "https://multiembed.mov/directstream.php?video_id={$tmdb_id}&s={$s}&e={$e}&tmdb=1",
            "VidBinge" => "https://vidbinge.dev/embed/tv/{$tmdb_id}/{$s}/{$e}",
            "VidSrc" => "https://vidsrc.xyz/embed/tv?tmdb={$tmdb_id}&season={$s}&episode={$e}",
            "AutoEmbed" => "https://player.autoembed.cc/embed/tv/{$tmdb_id}/{$s}/{$e}",
            "2Embed" => "https://www.2embed.cc/embedtv/{$tmdb_id}?s={$s}&e={$e}",
            "MoviesAPI" => "https://moviesapi.club/tv/{$tmdb_id}-{$s}-{$e}",
        ];
    }
    
    return [
        "MappleTV" => "https://mappletv.uk/watch/movie/{$tmdb_id}{$autoplay}",
        "VidLink" => "https://vidlink.pro/movie/{$tmdb_id}",
        "PrimeWire" => "https://www.primewire.tf/embed/movie?tmdb={$tmdb_id}",
        "Embed SU" => "https://embed.su/embed/movie/{$tmdb_id}",
        "MultiEmbed" => "https://multiembed.mov/directstream.php?video_id={$tmdb_id}&tmdb=1",
        "VidBinge" => "https://vidbinge.dev/embed/movie/{$tmdb_id}",
        "VidSrc" => "https://vidsrc.xyz/embed/movie/{$tmdb_id}",
        "MoviesAPI" => "https://moviesapi.club/movie/{$tmdb_id}",
        "AutoEmbed" => "https://player.autoembed.cc/embed/movie/{$tmdb_id}",
        "2Embed" => "https://www.2embed.cc/embed/{$tmdb_id}",
    ];
}
