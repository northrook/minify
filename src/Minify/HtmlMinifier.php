
/**
* Trim unnecessary whitespace around brackets
*/
private function trimElementTagBrackets() : HtmlMinifier
{
$this->string = \preg_replace( [ '#(^<\w+.*?>)\s+#', '#\s+(</\w+?>)$#', ], '$1', $this->string );
$this->string = \preg_replace( '# (<\/[bi]>) *?<#m', '$1 <', $this->string );
$this->string = \preg_replace( '# (<\/\w+?>)<#m', '$1 <', $this->string );
return $this;
}