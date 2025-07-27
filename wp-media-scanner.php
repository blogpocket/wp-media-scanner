<?php
/**
 * Plugin Name: WP Media Scanner
 * Description: Escanea contenido para listar vídeos, audios y otros medios (media), incluyendo Spotify.
 * Version: 2.1
 * Author: Antonio
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_Media_Scanner {
    private $options;
    public function __construct() {
        add_action('admin_menu',[$this,'register_admin_pages']);
        add_action('admin_init',[$this,'register_settings']);
    }
    public function register_admin_pages() {
        add_menu_page('Media Scanner','Media Scanner','manage_options','wp-media-scanner',[$this,'render_admin_page'],'dashicons-format-video',80);
        add_submenu_page('wp-media-scanner','Ajustes Scanner','Ajustes','manage_options','wp-media-scanner-settings',[$this,'render_settings_page']);
    }
    public function register_settings() {
        register_setting('wpm_scanner_group','wpm_scanner_options',[$this,'validate_options']);
        add_settings_section('wpm_scanner_section','Configuración de salida',null,'wp-media-scanner-settings');
        add_settings_field('media_types','Tipos de Media',[$this,'field_media_types'],'wp-media-scanner-settings','wpm_scanner_section');
        add_settings_field('embed_types','Tipos de Origen',[$this,'field_embed_types'],'wp-media-scanner-settings','wpm_scanner_section');
        add_settings_field('per_page','Elementos por página',[$this,'field_per_page'],'wp-media-scanner-settings','wpm_scanner_section');
    }
    public function validate_options($input) {
        $valid=[];
        $valid['media_types']=array_intersect((array)$input['media_types'],['audio','video','media']);
        $valid['embed_types']=array_intersect((array)$input['embed_types'],['link','iframe']);
        $pp=intval($input['per_page']);
        $valid['per_page']=$pp>0?$pp:25;
        return $valid;
    }
    public function field_media_types() {
        $opts=$this->get_options();
        foreach(['audio','video','media'] as $m){
            $chk=in_array($m,$opts['media_types'])?'checked':'';
            echo "<label><input type='checkbox' name='wpm_scanner_options[media_types][]' value='$m' $chk> $m</label><br>";
        }
    }
    public function field_embed_types() {
        $opts=$this->get_options();
        foreach(['link','iframe'] as $e){
            $chk=in_array($e,$opts['embed_types'])?'checked':'';
            echo "<label><input type='checkbox' name='wpm_scanner_options[embed_types][]' value='$e' $chk> $e</label><br>";
        }
    }
    public function field_per_page() {
        $opts=$this->get_options();
        echo "<input type='number' name='wpm_scanner_options[per_page]' value='".esc_attr($opts['per_page'])."' min='1'>";
    }
    private function get_options(){
        if(!$this->options){
            $defaults=['media_types'=>['audio','video','media'],'embed_types'=>['link','iframe'],'per_page'=>25];
            $this->options=wp_parse_args(get_option('wpm_scanner_options',[]),$defaults);
        }
        return $this->options;
    }
    public function render_settings_page(){
        echo '<div class="wrap"><h1>Ajustes Media Scanner</h1><form method="post" action="options.php">';
        settings_fields('wpm_scanner_group');
        do_settings_sections('wp-media-scanner-settings');
        submit_button();
        echo '</form></div>';
    }
    public function render_admin_page(){
        $opts=$this->get_options();
        echo '<div class="wrap"><h1>WP Media Scanner</h1>';
        $all=$this->scan_media_in_content();
        $filtered=array_filter($all, function($it) use($opts){
            return in_array($it['media_type'],$opts['media_types']) && in_array($it['embed_type'],$opts['embed_types']);
        });
        $page=max(1,intval($_GET['paged']??1));
        $per=$opts['per_page'];
        $total=count($filtered);
        $pages=ceil($total/$per);
        $offset=($page-1)*$per;
        $slice=array_slice($filtered,$offset,$per);
        if(!$slice) echo '<p>No hay elementos con estos filtros.</p>';
        else{
            echo '<table class="widefat fixed"><thead><tr><th>Título</th><th>Tipo</th><th>Origen</th><th>Link/Código</th></tr></thead><tbody>';
            foreach($slice as $it){
                echo '<tr><td>'.esc_html($it['title']).'</td><td>'.esc_html(ucfirst($it['media_type'])).'</td><td>'.esc_html(ucfirst($it['embed_type'])).'</td><td><code>'.esc_html($it['snippet']).'</code></td></tr>';
            }
            echo '</tbody></table>';
            echo '<div class="tablenav"><div class="tablenav-pages">'.paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'','current'=>$page,'total'=>$pages]).'</div></div>';
        }
        echo '</div>';
    }
    private function scan_media_in_content(){
        $post_types=get_post_types(['public'=>true],'names');
        $query=new WP_Query(['post_type'=>$post_types,'posts_per_page'=>-1]);
        $res=[];
        while($query->have_posts()){ $query->the_post();
            foreach($this->extract_media(get_the_content()) as $it){
                $res[]=['title'=>get_the_title(),'media_type'=>$it['media_type'],'embed_type'=>$it['embed_type'],'snippet'=>$it['snippet']];
            }
        }
        wp_reset_postdata();return $res;
    }
    private function extract_media($content){
        $found=[]; $anchors=[];
        if(preg_match_all('/<a[^>]+href="([^"]+)"/i',$content,$a)) $anchors=$a[1];
        $keys=['tv','play','multimedia','audio','audios','video','videos','media','medios'];
        $pattern=get_shortcode_regex();
        if(preg_match_all('/'.$pattern.'/s',$content,$m))foreach($m[0]as$c){
            if(stripos($c,'[video')!==false)$found[]=['media_type'=>'video','embed_type'=>'shortcode','snippet'=>$c];
            if(stripos($c,'[audio')!==false)$found[]=['media_type'=>'audio','embed_type'=>'shortcode','snippet'=>$c];
        }
        if(preg_match_all('/<video[^>]*>.*?<\/video>/is',$content,$v))foreach($v[0]as$t)$found[]=['media_type'=>'video','embed_type'=>'html','snippet'=>$t];
        if(preg_match_all('/<audio[^>]*>.*?<\/audio>/is',$content,$au))foreach($au[0]as$t)$found[]=['media_type'=>'audio','embed_type'=>'html','snippet'=>$t];
        $iframes=[]; if(preg_match_all('/<iframe[^>]+src="([^"]+)"/is',$content,$if))foreach($if[1]as$i=>$src){
            $iframes[]=$src; $tag=$if[0][$i]; $type='media';
            if(stripos($src,'youtu')!==false||stripos($src,'vimeo')!==false)$type='video';
            elseif(stripos($src,'spotify.com')!==false)$type='audio';
            $found[]=['media_type'=>$type,'embed_type'=>'iframe','snippet'=>$tag];
        }
        if(preg_match_all('/https?:\/\/\S+\.(mp4|webm|ogg|mp3|wav)/i',$content,$ln))foreach($ln[0]as$u){
            $ext=pathinfo(parse_url($u,PHP_URL_PATH),PATHINFO_EXTENSION);
            $found[]=['media_type'=>in_array(strtolower($ext),['mp4','webm','ogg'])?'video':'audio','embed_type'=>'link','snippet'=>$u];
        }
        if(preg_match_all('/https?:\/\/(?:open\.)?spotify\.com\/\S+/i',$content,$sp))foreach($sp[0]as$u){
            if(in_array($u,$iframes)||in_array($u,$anchors))continue;
            $found[]=['media_type'=>'audio','embed_type'=>'link','snippet'=>$u];
        }
        if(preg_match_all('/https?:\/\/(?:www\.)?youtu\.be\/[\w\-?=]+/i',$content,$yt))foreach($yt[0]as$u){
            if(in_array($u,$iframes)||in_array($u,$anchors))continue;
            $found[]=['media_type'=>'video','embed_type'=>'link','snippet'=>$u];
        }
        if(preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>.*?<\/a>/is',$content,$lnk))foreach($lnk[1]as$i=>$h){
            if(in_array($h,$iframes))continue;
            $tag=$lnk[0][$i]; $mt='';
            if(preg_match('/\.(mp4|webm|ogg)$/i',$h))$mt='video';
            elseif(preg_match('/\.(mp3|wav)$/i',$h))$mt='audio';
            elseif(stripos($h,'youtu')!==false||stripos($h,'vimeo')!==false)$mt='video';
            elseif(stripos($h,'soundcloud.com')!==false)$mt='audio';
            elseif(preg_match('/('.implode('|',$keys).')/i',$h))$mt='media';
            if($mt)$found[]=['media_type'=>$mt,'embed_type'=>'link','snippet'=>$tag];
        }
        return $found;
    }
}
new WP_Media_Scanner();