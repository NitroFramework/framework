<?php

use Nitro\Fusion\PhpJsFunctions\Helpers\Bc;
use Nitro\Fusion\PhpJsFunctions\Helpers\PhpCastString;
use Nitro\Fusion\PhpJsFunctions\Helpers\PhpCastFloat;
use Nitro\Fusion\PhpJsFunctions\Helpers\PhpCastInt;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayChangeKeyCase;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayChunk;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayColumn;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayCombine;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayCountValues;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayDiff;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayDiffAssoc;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayDiffKey;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayDiffUassoc;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayDiffUkey;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayFill;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayFillKeys;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayFilter;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayFlip;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayIntersect;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayIntersectAssoc;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayIntersectKey;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayIntersectUassoc;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayIntersectUkey;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayKeyExists;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayKeys;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayMap;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayMerge;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayMergeRecursive;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayMultisort;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayPad;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayPop;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayProduct;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayPush;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayRand;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayReduce;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayReplace;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayReplaceRecursive;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayReverse;
use Nitro\Fusion\PhpJsFunctions\Array\ArraySearch;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayShift;
use Nitro\Fusion\PhpJsFunctions\Array\ArraySlice;
use Nitro\Fusion\PhpJsFunctions\Array\ArraySplice;
use Nitro\Fusion\PhpJsFunctions\Array\ArraySum;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayUdiff;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayUdiffAssoc;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayUdiffUassoc;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayUintersect;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayUintersectUassoc;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayUnique;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayUnshift;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayValues;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayWalk;
use Nitro\Fusion\PhpJsFunctions\Array\ArrayWalkRecursive;
use Nitro\Fusion\PhpJsFunctions\Array\Arsort;
use Nitro\Fusion\PhpJsFunctions\Array\Asort;
use Nitro\Fusion\PhpJsFunctions\Array\Count;
use Nitro\Fusion\PhpJsFunctions\Array\Current;
use Nitro\Fusion\PhpJsFunctions\Array\Each;
use Nitro\Fusion\PhpJsFunctions\Array\End;
use Nitro\Fusion\PhpJsFunctions\Array\InArray;
use Nitro\Fusion\PhpJsFunctions\Array\Key;
use Nitro\Fusion\PhpJsFunctions\Array\Krsort;
use Nitro\Fusion\PhpJsFunctions\Array\Ksort;
use Nitro\Fusion\PhpJsFunctions\Array\Natcasesort;
use Nitro\Fusion\PhpJsFunctions\Array\Natsort;
use Nitro\Fusion\PhpJsFunctions\Array\Next;
use Nitro\Fusion\PhpJsFunctions\Array\Pos;
use Nitro\Fusion\PhpJsFunctions\Array\Prev;
use Nitro\Fusion\PhpJsFunctions\Array\Range;
use Nitro\Fusion\PhpJsFunctions\Array\Reset;
use Nitro\Fusion\PhpJsFunctions\Array\Rsort;
use Nitro\Fusion\PhpJsFunctions\Array\Shuffle;
use Nitro\Fusion\PhpJsFunctions\Array\Sizeof;
use Nitro\Fusion\PhpJsFunctions\Array\Sort;
use Nitro\Fusion\PhpJsFunctions\Array\Uasort;
use Nitro\Fusion\PhpJsFunctions\Array\Uksort;
use Nitro\Fusion\PhpJsFunctions\Array\Usort;
use Nitro\Fusion\PhpJsFunctions\Bc\Bcadd;
use Nitro\Fusion\PhpJsFunctions\Bc\Bccomp;
use Nitro\Fusion\PhpJsFunctions\Bc\Bcdiv;
use Nitro\Fusion\PhpJsFunctions\Bc\Bcmul;
use Nitro\Fusion\PhpJsFunctions\Bc\Bcround;
use Nitro\Fusion\PhpJsFunctions\Bc\Bcscale;
use Nitro\Fusion\PhpJsFunctions\Bc\Bcsub;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypeAlnum;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypeAlpha;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypeCntrl;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypeDigit;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypeGraph;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypeLower;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypePrint;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypePunct;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypeSpace;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypeUpper;
use Nitro\Fusion\PhpJsFunctions\Ctype\CtypeXdigit;
use Nitro\Fusion\PhpJsFunctions\Datetime\Checkdate;
use Nitro\Fusion\PhpJsFunctions\Datetime\Date;
use Nitro\Fusion\PhpJsFunctions\Datetime\DateParse;
use Nitro\Fusion\PhpJsFunctions\Datetime\Getdate;
use Nitro\Fusion\PhpJsFunctions\Datetime\Gettimeofday;
use Nitro\Fusion\PhpJsFunctions\Datetime\Gmdate;
use Nitro\Fusion\PhpJsFunctions\Datetime\Gmmktime;
use Nitro\Fusion\PhpJsFunctions\Datetime\Gmstrftime;
use Nitro\Fusion\PhpJsFunctions\Datetime\Idate;
use Nitro\Fusion\PhpJsFunctions\Datetime\Microtime;
use Nitro\Fusion\PhpJsFunctions\Datetime\Mktime;
use Nitro\Fusion\PhpJsFunctions\Datetime\Strftime;
use Nitro\Fusion\PhpJsFunctions\Datetime\Strptime;
use Nitro\Fusion\PhpJsFunctions\Datetime\Strtotime;
use Nitro\Fusion\PhpJsFunctions\Datetime\Time;
use Nitro\Fusion\PhpJsFunctions\Exec\Escapeshellarg;
use Nitro\Fusion\PhpJsFunctions\Filesystem\Basename;
use Nitro\Fusion\PhpJsFunctions\Filesystem\Dirname;
use Nitro\Fusion\PhpJsFunctions\Filesystem\FileGetContents;
use Nitro\Fusion\PhpJsFunctions\Filesystem\Pathinfo;
use Nitro\Fusion\PhpJsFunctions\Filesystem\Realpath;
use Nitro\Fusion\PhpJsFunctions\Funchand\CallUserFunc;
use Nitro\Fusion\PhpJsFunctions\Funchand\CallUserFuncArray;
use Nitro\Fusion\PhpJsFunctions\Funchand\CreateFunction;
use Nitro\Fusion\PhpJsFunctions\Funchand\FunctionExists;
use Nitro\Fusion\PhpJsFunctions\Funchand\GetDefinedFunctions;
use Nitro\Fusion\PhpJsFunctions\I18n\I18nLocGetDefault;
use Nitro\Fusion\PhpJsFunctions\I18n\I18nLocSetDefault;
use Nitro\Fusion\PhpJsFunctions\Info\AssertOptions;
use Nitro\Fusion\PhpJsFunctions\Info\Getenv;
use Nitro\Fusion\PhpJsFunctions\Info\IniGet;
use Nitro\Fusion\PhpJsFunctions\Info\IniSet;
use Nitro\Fusion\PhpJsFunctions\Info\SetTimeLimit;
use Nitro\Fusion\PhpJsFunctions\Info\VersionCompare;
use Nitro\Fusion\PhpJsFunctions\Json\JsonDecode;
use Nitro\Fusion\PhpJsFunctions\Json\JsonEncode;
use Nitro\Fusion\PhpJsFunctions\Json\JsonLastError;
use Nitro\Fusion\PhpJsFunctions\Math\Abs;
use Nitro\Fusion\PhpJsFunctions\Math\Acos;
use Nitro\Fusion\PhpJsFunctions\Math\Acosh;
use Nitro\Fusion\PhpJsFunctions\Math\Asin;
use Nitro\Fusion\PhpJsFunctions\Math\Asinh;
use Nitro\Fusion\PhpJsFunctions\Math\Atan;
use Nitro\Fusion\PhpJsFunctions\Math\Atan2;
use Nitro\Fusion\PhpJsFunctions\Math\Atanh;
use Nitro\Fusion\PhpJsFunctions\Math\BaseConvert;
use Nitro\Fusion\PhpJsFunctions\Math\Bindec;
use Nitro\Fusion\PhpJsFunctions\Math\Ceil;
use Nitro\Fusion\PhpJsFunctions\Math\Cos;
use Nitro\Fusion\PhpJsFunctions\Math\Cosh;
use Nitro\Fusion\PhpJsFunctions\Math\Decbin;
use Nitro\Fusion\PhpJsFunctions\Math\Dechex;
use Nitro\Fusion\PhpJsFunctions\Math\Decoct;
use Nitro\Fusion\PhpJsFunctions\Math\Deg2rad;
use Nitro\Fusion\PhpJsFunctions\Math\Exp;
use Nitro\Fusion\PhpJsFunctions\Math\Expm1;
use Nitro\Fusion\PhpJsFunctions\Math\Floor;
use Nitro\Fusion\PhpJsFunctions\Math\Fmod;
use Nitro\Fusion\PhpJsFunctions\Math\Getrandmax;
use Nitro\Fusion\PhpJsFunctions\Math\Hexdec;
use Nitro\Fusion\PhpJsFunctions\Math\Hypot;
use Nitro\Fusion\PhpJsFunctions\Math\IsFinite;
use Nitro\Fusion\PhpJsFunctions\Math\IsInfinite;
use Nitro\Fusion\PhpJsFunctions\Math\IsNan;
use Nitro\Fusion\PhpJsFunctions\Math\LcgValue;
use Nitro\Fusion\PhpJsFunctions\Math\Log;
use Nitro\Fusion\PhpJsFunctions\Math\Log10;
use Nitro\Fusion\PhpJsFunctions\Math\Log1p;
use Nitro\Fusion\PhpJsFunctions\Math\Max;
use Nitro\Fusion\PhpJsFunctions\Math\Min;
use Nitro\Fusion\PhpJsFunctions\Math\MtGetrandmax;
use Nitro\Fusion\PhpJsFunctions\Math\MtRand;
use Nitro\Fusion\PhpJsFunctions\Math\Octdec;
use Nitro\Fusion\PhpJsFunctions\Math\Pi;
use Nitro\Fusion\PhpJsFunctions\Math\Pow;
use Nitro\Fusion\PhpJsFunctions\Math\Rad2deg;
use Nitro\Fusion\PhpJsFunctions\Math\Rand;
use Nitro\Fusion\PhpJsFunctions\Math\Round;
use Nitro\Fusion\PhpJsFunctions\Math\Sin;
use Nitro\Fusion\PhpJsFunctions\Math\Sinh;
use Nitro\Fusion\PhpJsFunctions\Math\Sqrt;
use Nitro\Fusion\PhpJsFunctions\Math\Tan;
use Nitro\Fusion\PhpJsFunctions\Math\Tanh;
use Nitro\Fusion\PhpJsFunctions\Misc\Pack;
use Nitro\Fusion\PhpJsFunctions\Misc\Uniqid;
use Nitro\Fusion\PhpJsFunctions\Netgopher\GopherParsedir;
use Nitro\Fusion\PhpJsFunctions\Network\InetNtop;
use Nitro\Fusion\PhpJsFunctions\Network\InetPton;
use Nitro\Fusion\PhpJsFunctions\Network\Ip2long;
use Nitro\Fusion\PhpJsFunctions\Network\Long2ip;
use Nitro\Fusion\PhpJsFunctions\Network\Setcookie;
use Nitro\Fusion\PhpJsFunctions\Network\Setrawcookie;
use Nitro\Fusion\PhpJsFunctions\Pcre\PregMatch;
use Nitro\Fusion\PhpJsFunctions\Pcre\PregQuote;
use Nitro\Fusion\PhpJsFunctions\Pcre\PregReplace;
use Nitro\Fusion\PhpJsFunctions\Pcre\SqlRegcase;
use Nitro\Fusion\PhpJsFunctions\Strings\Addcslashes;
use Nitro\Fusion\PhpJsFunctions\Strings\Addslashes;
use Nitro\Fusion\PhpJsFunctions\Strings\Bin2hex;
use Nitro\Fusion\PhpJsFunctions\Strings\Chop;
use Nitro\Fusion\PhpJsFunctions\Strings\Chr;
use Nitro\Fusion\PhpJsFunctions\Strings\ChunkSplit;
use Nitro\Fusion\PhpJsFunctions\Strings\ConvertCyrString;
use Nitro\Fusion\PhpJsFunctions\Strings\ConvertUuencode;
use Nitro\Fusion\PhpJsFunctions\Strings\CountChars;
use Nitro\Fusion\PhpJsFunctions\Strings\Crc32;
use Nitro\Fusion\PhpJsFunctions\Strings\_Echo;
use Nitro\Fusion\PhpJsFunctions\Strings\Explode;
use Nitro\Fusion\PhpJsFunctions\Strings\GetHtmlTranslationTable;
use Nitro\Fusion\PhpJsFunctions\Strings\Hex2bin;
use Nitro\Fusion\PhpJsFunctions\Strings\HtmlEntityDecode;
use Nitro\Fusion\PhpJsFunctions\Strings\Htmlentities;
use Nitro\Fusion\PhpJsFunctions\Strings\Htmlspecialchars;
use Nitro\Fusion\PhpJsFunctions\Strings\HtmlspecialcharsDecode;
use Nitro\Fusion\PhpJsFunctions\Strings\Implode;
use Nitro\Fusion\PhpJsFunctions\Strings\Join;
use Nitro\Fusion\PhpJsFunctions\Strings\Lcfirst;
use Nitro\Fusion\PhpJsFunctions\Strings\Levenshtein;
use Nitro\Fusion\PhpJsFunctions\Strings\Localeconv;
use Nitro\Fusion\PhpJsFunctions\Strings\Ltrim;
use Nitro\Fusion\PhpJsFunctions\Strings\MbStrlen;
use Nitro\Fusion\PhpJsFunctions\Strings\Md5;
use Nitro\Fusion\PhpJsFunctions\Strings\Md5File;
use Nitro\Fusion\PhpJsFunctions\Strings\Metaphone;
use Nitro\Fusion\PhpJsFunctions\Strings\MoneyFormat;
use Nitro\Fusion\PhpJsFunctions\Strings\Nl2br;
use Nitro\Fusion\PhpJsFunctions\Strings\NlLanginfo;
use Nitro\Fusion\PhpJsFunctions\Strings\NumberFormat;
use Nitro\Fusion\PhpJsFunctions\Strings\Ord;
use Nitro\Fusion\PhpJsFunctions\Strings\ParseStr;
use Nitro\Fusion\PhpJsFunctions\Strings\Printf;
use Nitro\Fusion\PhpJsFunctions\Strings\QuotedPrintableDecode;
use Nitro\Fusion\PhpJsFunctions\Strings\QuotedPrintableEncode;
use Nitro\Fusion\PhpJsFunctions\Strings\Quotemeta;
use Nitro\Fusion\PhpJsFunctions\Strings\Rtrim;
use Nitro\Fusion\PhpJsFunctions\Strings\Setlocale;
use Nitro\Fusion\PhpJsFunctions\Strings\Sha1;
use Nitro\Fusion\PhpJsFunctions\Strings\Sha1File;
use Nitro\Fusion\PhpJsFunctions\Strings\SimilarText;
use Nitro\Fusion\PhpJsFunctions\Strings\Soundex;
use Nitro\Fusion\PhpJsFunctions\Strings\Split;
use Nitro\Fusion\PhpJsFunctions\Strings\Sprintf;
use Nitro\Fusion\PhpJsFunctions\Strings\Sscanf;
use Nitro\Fusion\PhpJsFunctions\Strings\StrGetcsv;
use Nitro\Fusion\PhpJsFunctions\Strings\StrIreplace;
use Nitro\Fusion\PhpJsFunctions\Strings\StrPad;
use Nitro\Fusion\PhpJsFunctions\Strings\StrRepeat;
use Nitro\Fusion\PhpJsFunctions\Strings\StrReplace;
use Nitro\Fusion\PhpJsFunctions\Strings\StrRot13;
use Nitro\Fusion\PhpJsFunctions\Strings\StrShuffle;
use Nitro\Fusion\PhpJsFunctions\Strings\StrSplit;
use Nitro\Fusion\PhpJsFunctions\Strings\StrWordCount;
use Nitro\Fusion\PhpJsFunctions\Strings\Strcasecmp;
use Nitro\Fusion\PhpJsFunctions\Strings\Strchr;
use Nitro\Fusion\PhpJsFunctions\Strings\Strcmp;
use Nitro\Fusion\PhpJsFunctions\Strings\Strcoll;
use Nitro\Fusion\PhpJsFunctions\Strings\Strcspn;
use Nitro\Fusion\PhpJsFunctions\Strings\StripTags;
use Nitro\Fusion\PhpJsFunctions\Strings\Stripos;
use Nitro\Fusion\PhpJsFunctions\Strings\Stripslashes;
use Nitro\Fusion\PhpJsFunctions\Strings\Stristr;
use Nitro\Fusion\PhpJsFunctions\Strings\Strlen;
use Nitro\Fusion\PhpJsFunctions\Strings\Strnatcasecmp;
use Nitro\Fusion\PhpJsFunctions\Strings\Strnatcmp;
use Nitro\Fusion\PhpJsFunctions\Strings\Strncasecmp;
use Nitro\Fusion\PhpJsFunctions\Strings\Strncmp;
use Nitro\Fusion\PhpJsFunctions\Strings\Strpbrk;
use Nitro\Fusion\PhpJsFunctions\Strings\Strpos;
use Nitro\Fusion\PhpJsFunctions\Strings\Strrchr;
use Nitro\Fusion\PhpJsFunctions\Strings\Strrev;
use Nitro\Fusion\PhpJsFunctions\Strings\Strripos;
use Nitro\Fusion\PhpJsFunctions\Strings\Strrpos;
use Nitro\Fusion\PhpJsFunctions\Strings\Strspn;
use Nitro\Fusion\PhpJsFunctions\Strings\Strstr;
use Nitro\Fusion\PhpJsFunctions\Strings\Strtok;
use Nitro\Fusion\PhpJsFunctions\Strings\Strtolower;
use Nitro\Fusion\PhpJsFunctions\Strings\Strtoupper;
use Nitro\Fusion\PhpJsFunctions\Strings\Strtr;
use Nitro\Fusion\PhpJsFunctions\Strings\Substr;
use Nitro\Fusion\PhpJsFunctions\Strings\SubstrCompare;
use Nitro\Fusion\PhpJsFunctions\Strings\SubstrCount;
use Nitro\Fusion\PhpJsFunctions\Strings\SubstrReplace;
use Nitro\Fusion\PhpJsFunctions\Strings\Trim;
use Nitro\Fusion\PhpJsFunctions\Strings\Ucfirst;
use Nitro\Fusion\PhpJsFunctions\Strings\Ucwords;
use Nitro\Fusion\PhpJsFunctions\Strings\Vprintf;
use Nitro\Fusion\PhpJsFunctions\Strings\Vsprintf;
use Nitro\Fusion\PhpJsFunctions\Strings\Wordwrap;
use Nitro\Fusion\PhpJsFunctions\Url\Base64Decode;
use Nitro\Fusion\PhpJsFunctions\Url\Base64Encode;
use Nitro\Fusion\PhpJsFunctions\Url\HttpBuildQuery;
use Nitro\Fusion\PhpJsFunctions\Url\ParseUrl;
use Nitro\Fusion\PhpJsFunctions\Url\Rawurldecode;
use Nitro\Fusion\PhpJsFunctions\Url\Rawurlencode;
use Nitro\Fusion\PhpJsFunctions\Url\Urldecode;
use Nitro\Fusion\PhpJsFunctions\Url\Urlencode;
use Nitro\Fusion\PhpJsFunctions\Var\Boolval;
use Nitro\Fusion\PhpJsFunctions\Var\Doubleval;
use Nitro\Fusion\PhpJsFunctions\Var\_Empty;
use Nitro\Fusion\PhpJsFunctions\Var\Floatval;
use Nitro\Fusion\PhpJsFunctions\Var\Gettype;
use Nitro\Fusion\PhpJsFunctions\Var\Intval;
use Nitro\Fusion\PhpJsFunctions\Var\IsArray;
use Nitro\Fusion\PhpJsFunctions\Var\IsBinary;
use Nitro\Fusion\PhpJsFunctions\Var\IsBool;
use Nitro\Fusion\PhpJsFunctions\Var\IsBuffer;
use Nitro\Fusion\PhpJsFunctions\Var\IsCallable;
use Nitro\Fusion\PhpJsFunctions\Var\IsDouble;
use Nitro\Fusion\PhpJsFunctions\Var\IsFloat;
use Nitro\Fusion\PhpJsFunctions\Var\IsInt;
use Nitro\Fusion\PhpJsFunctions\Var\IsInteger;
use Nitro\Fusion\PhpJsFunctions\Var\IsLong;
use Nitro\Fusion\PhpJsFunctions\Var\IsNull;
use Nitro\Fusion\PhpJsFunctions\Var\IsNumeric;
use Nitro\Fusion\PhpJsFunctions\Var\IsObject;
use Nitro\Fusion\PhpJsFunctions\Var\IsReal;
use Nitro\Fusion\PhpJsFunctions\Var\IsScalar;
use Nitro\Fusion\PhpJsFunctions\Var\IsString;
use Nitro\Fusion\PhpJsFunctions\Var\IsUnicode;
use Nitro\Fusion\PhpJsFunctions\Var\_Isset;
use Nitro\Fusion\PhpJsFunctions\Var\PrintR;
use Nitro\Fusion\PhpJsFunctions\Var\Serialize;
use Nitro\Fusion\PhpJsFunctions\Var\Strval;
use Nitro\Fusion\PhpJsFunctions\Var\Unserialize;
use Nitro\Fusion\PhpJsFunctions\Var\VarDump;
use Nitro\Fusion\PhpJsFunctions\Var\VarExport;
use Nitro\Fusion\PhpJsFunctions\Xdiff\XdiffStringDiff;
use Nitro\Fusion\PhpJsFunctions\Xdiff\XdiffStringPatch;
use Nitro\Fusion\PhpJsFunctions\Xml\Utf8Decode;
use Nitro\Fusion\PhpJsFunctions\Xml\Utf8Encode;


return [
    '_bc' => Bc::class,
    '_phpCastString' => PhpCastString::class,
    '_php_cast_float' => PhpCastFloat::class,
    '_php_cast_int' => PhpCastInt::class,
    'array_change_key_case' => ArrayChangeKeyCase::class,
    'array_chunk' => ArrayChunk::class,
    'array_column' => ArrayColumn::class,
    'array_combine' => ArrayCombine::class,
    'array_count_values' => ArrayCountValues::class,
    'array_diff' => ArrayDiff::class,
    'array_diff_assoc' => ArrayDiffAssoc::class,
    'array_diff_key' => ArrayDiffKey::class,
    'array_diff_uassoc' => ArrayDiffUassoc::class,
    'array_diff_ukey' => ArrayDiffUkey::class,
    'array_fill' => ArrayFill::class,
    'array_fill_keys' => ArrayFillKeys::class,
    'array_filter' => ArrayFilter::class,
    'array_flip' => ArrayFlip::class,
    'array_intersect' => ArrayIntersect::class,
    'array_intersect_assoc' => ArrayIntersectAssoc::class,
    'array_intersect_key' => ArrayIntersectKey::class,
    'array_intersect_uassoc' => ArrayIntersectUassoc::class,
    'array_intersect_ukey' => ArrayIntersectUkey::class,
    'array_key_exists' => ArrayKeyExists::class,
    'array_keys' => ArrayKeys::class,
    'array_map' => ArrayMap::class,
    'array_merge' => ArrayMerge::class,
    'array_merge_recursive' => ArrayMergeRecursive::class,
    'array_multisort' => ArrayMultisort::class,
    'array_pad' => ArrayPad::class,
    'array_pop' => ArrayPop::class,
    'array_product' => ArrayProduct::class,
    'array_push' => ArrayPush::class,
    'array_rand' => ArrayRand::class,
    'array_reduce' => ArrayReduce::class,
    'array_replace' => ArrayReplace::class,
    'array_replace_recursive' => ArrayReplaceRecursive::class,
    'array_reverse' => ArrayReverse::class,
    'array_search' => ArraySearch::class,
    'array_shift' => ArrayShift::class,
    'array_slice' => ArraySlice::class,
    'array_splice' => ArraySplice::class,
    'array_sum' => ArraySum::class,
    'array_udiff' => ArrayUdiff::class,
    'array_udiff_assoc' => ArrayUdiffAssoc::class,
    'array_udiff_uassoc' => ArrayUdiffUassoc::class,
    'array_uintersect' => ArrayUintersect::class,
    'array_uintersect_uassoc' => ArrayUintersectUassoc::class,
    'array_unique' => ArrayUnique::class,
    'array_unshift' => ArrayUnshift::class,
    'array_values' => ArrayValues::class,
    'array_walk' => ArrayWalk::class,
    'array_walk_recursive' => ArrayWalkRecursive::class,
    'arsort' => Arsort::class,
    'asort' => Asort::class,
    'count' => Count::class,
    'current' => Current::class,
    'each' => Each::class,
    'end' => End::class,
    'in_array' => InArray::class,
    'key' => Key::class,
    'krsort' => Krsort::class,
    'ksort' => Ksort::class,
    'natcasesort' => Natcasesort::class,
    'natsort' => Natsort::class,
    'next' => Next::class,
    'pos' => Pos::class,
    'prev' => Prev::class,
    'range' => Range::class,
    'reset' => Reset::class,
    'rsort' => Rsort::class,
    'shuffle' => Shuffle::class,
    'sizeof' => Sizeof::class,
    'sort' => Sort::class,
    'uasort' => Uasort::class,
    'uksort' => Uksort::class,
    'usort' => Usort::class,
    'bcadd' => Bcadd::class,
    'bccomp' => Bccomp::class,
    'bcdiv' => Bcdiv::class,
    'bcmul' => Bcmul::class,
    'bcround' => Bcround::class,
    'bcscale' => Bcscale::class,
    'bcsub' => Bcsub::class,
    'ctype_alnum' => CtypeAlnum::class,
    'ctype_alpha' => CtypeAlpha::class,
    'ctype_cntrl' => CtypeCntrl::class,
    'ctype_digit' => CtypeDigit::class,
    'ctype_graph' => CtypeGraph::class,
    'ctype_lower' => CtypeLower::class,
    'ctype_print' => CtypePrint::class,
    'ctype_punct' => CtypePunct::class,
    'ctype_space' => CtypeSpace::class,
    'ctype_upper' => CtypeUpper::class,
    'ctype_xdigit' => CtypeXdigit::class,
    'checkdate' => Checkdate::class,
    'date' => Date::class,
    'date_parse' => DateParse::class,
    'getdate' => Getdate::class,
    'gettimeofday' => Gettimeofday::class,
    'gmdate' => Gmdate::class,
    'gmmktime' => Gmmktime::class,
    'gmstrftime' => Gmstrftime::class,
    'idate' => Idate::class,
    'microtime' => Microtime::class,
    'mktime' => Mktime::class,
    'strftime' => Strftime::class,
    'strptime' => Strptime::class,
    'strtotime' => Strtotime::class,
    'time' => Time::class,
    'escapeshellarg' => Escapeshellarg::class,
    'basename' => Basename::class,
    'dirname' => Dirname::class,
    'file_get_contents' => FileGetContents::class,
    'pathinfo' => Pathinfo::class,
    'realpath' => Realpath::class,
    'call_user_func' => CallUserFunc::class,
    'call_user_func_array' => CallUserFuncArray::class,
    'create_function' => CreateFunction::class,
    'function_exists' => FunctionExists::class,
    'get_defined_functions' => GetDefinedFunctions::class,
    'i18n_loc_get_default' => I18nLocGetDefault::class,
    'i18n_loc_set_default' => I18nLocSetDefault::class,
    'assert_options' => AssertOptions::class,
    'getenv' => Getenv::class,
    'ini_get' => IniGet::class,
    'ini_set' => IniSet::class,
    'set_time_limit' => SetTimeLimit::class,
    'version_compare' => VersionCompare::class,
    'json_decode' => JsonDecode::class,
    'json_encode' => JsonEncode::class,
    'json_last_error' => JsonLastError::class,
    'abs' => Abs::class,
    'acos' => Acos::class,
    'acosh' => Acosh::class,
    'asin' => Asin::class,
    'asinh' => Asinh::class,
    'atan' => Atan::class,
    'atan2' => Atan2::class,
    'atanh' => Atanh::class,
    'base_convert' => BaseConvert::class,
    'bindec' => Bindec::class,
    'ceil' => Ceil::class,
    'cos' => Cos::class,
    'cosh' => Cosh::class,
    'decbin' => Decbin::class,
    'dechex' => Dechex::class,
    'decoct' => Decoct::class,
    'deg2rad' => Deg2rad::class,
    'exp' => Exp::class,
    'expm1' => Expm1::class,
    'floor' => Floor::class,
    'fmod' => Fmod::class,
    'getrandmax' => Getrandmax::class,
    'hexdec' => Hexdec::class,
    'hypot' => Hypot::class,
    'is_finite' => IsFinite::class,
    'is_infinite' => IsInfinite::class,
    'is_nan' => IsNan::class,
    'lcg_value' => LcgValue::class,
    'log' => Log::class,
    'log10' => Log10::class,
    'log1p' => Log1p::class,
    'max' => Max::class,
    'min' => Min::class,
    'mt_getrandmax' => MtGetrandmax::class,
    'mt_rand' => MtRand::class,
    'octdec' => Octdec::class,
    'pi' => Pi::class,
    'pow' => Pow::class,
    'rad2deg' => Rad2deg::class,
    'rand' => Rand::class,
    'round' => Round::class,
    'sin' => Sin::class,
    'sinh' => Sinh::class,
    'sqrt' => Sqrt::class,
    'tan' => Tan::class,
    'tanh' => Tanh::class,
    'pack' => Pack::class,
    'uniqid' => Uniqid::class,
    'gopher_parsedir' => GopherParsedir::class,
    'inet_ntop' => InetNtop::class,
    'inet_pton' => InetPton::class,
    'ip2long' => Ip2long::class,
    'long2ip' => Long2ip::class,
    'setcookie' => Setcookie::class,
    'setrawcookie' => Setrawcookie::class,
    'preg_match' => PregMatch::class,
    'preg_quote' => PregQuote::class,
    'preg_replace' => PregReplace::class,
    'sql_regcase' => SqlRegcase::class,
    'addcslashes' => Addcslashes::class,
    'addslashes' => Addslashes::class,
    'bin2hex' => Bin2hex::class,
    'chop' => Chop::class,
    'chr' => Chr::class,
    'chunk_split' => ChunkSplit::class,
    'convert_cyr_string' => ConvertCyrString::class,
    'convert_uuencode' => ConvertUuencode::class,
    'count_chars' => CountChars::class,
    'crc32' => Crc32::class,
    'echo' => _Echo::class,
    'explode' => Explode::class,
    'get_html_translation_table' => GetHtmlTranslationTable::class,
    'hex2bin' => Hex2bin::class,
    'html_entity_decode' => HtmlEntityDecode::class,
    'htmlentities' => Htmlentities::class,
    'htmlspecialchars' => Htmlspecialchars::class,
    'htmlspecialchars_decode' => HtmlspecialcharsDecode::class,
    'implode' => Implode::class,
    'join' => Join::class,
    'lcfirst' => Lcfirst::class,
    'levenshtein' => Levenshtein::class,
    'localeconv' => Localeconv::class,
    'ltrim' => Ltrim::class,
    'md5' => Md5::class,
    'md5_file' => Md5File::class,
    'metaphone' => Metaphone::class,
    'money_format' => MoneyFormat::class,
    'nl2br' => Nl2br::class,
    'nl_langinfo' => NlLanginfo::class,
    'number_format' => NumberFormat::class,
    'ord' => Ord::class,
    'parse_str' => ParseStr::class,
    'printf' => Printf::class,
    'quoted_printable_decode' => QuotedPrintableDecode::class,
    'quoted_printable_encode' => QuotedPrintableEncode::class,
    'quotemeta' => Quotemeta::class,
    'rtrim' => Rtrim::class,
    'setlocale' => Setlocale::class,
    'sha1' => Sha1::class,
    'sha1_file' => Sha1File::class,
    'similar_text' => SimilarText::class,
    'soundex' => Soundex::class,
    'split' => Split::class,
    'sprintf' => Sprintf::class,
    'sscanf' => Sscanf::class,
    'str_getcsv' => StrGetcsv::class,
    'str_ireplace' => StrIreplace::class,
    'str_pad' => StrPad::class,
    'str_repeat' => StrRepeat::class,
    'str_replace' => StrReplace::class,
    'str_rot13' => StrRot13::class,
    'str_shuffle' => StrShuffle::class,
    'str_split' => StrSplit::class,
    'str_word_count' => StrWordCount::class,
    'strcasecmp' => Strcasecmp::class,
    'strchr' => Strchr::class,
    'strcmp' => Strcmp::class,
    'strcoll' => Strcoll::class,
    'strcspn' => Strcspn::class,
    'strip_tags' => StripTags::class,
    'stripos' => Stripos::class,
    'stripslashes' => Stripslashes::class,
    'stristr' => Stristr::class,
    'strlen' => Strlen::class,
    'mb_strlen' => MbStrlen::class,
    'strnatcasecmp' => Strnatcasecmp::class,
    'strnatcmp' => Strnatcmp::class,
    'strncasecmp' => Strncasecmp::class,
    'strncmp' => Strncmp::class,
    'strpbrk' => Strpbrk::class,
    'strpos' => Strpos::class,
    'strrchr' => Strrchr::class,
    'strrev' => Strrev::class,
    'strripos' => Strripos::class,
    'strrpos' => Strrpos::class,
    'strspn' => Strspn::class,
    'strstr' => Strstr::class,
    'strtok' => Strtok::class,
    'strtolower' => Strtolower::class,
    'strtoupper' => Strtoupper::class,
    'strtr' => Strtr::class,
    'substr' => Substr::class,
    'substr_compare' => SubstrCompare::class,
    'substr_count' => SubstrCount::class,
    'substr_replace' => SubstrReplace::class,
    'trim' => Trim::class,
    'ucfirst' => Ucfirst::class,
    'ucwords' => Ucwords::class,
    'vprintf' => Vprintf::class,
    'vsprintf' => Vsprintf::class,
    'wordwrap' => Wordwrap::class,
    'base64_decode' => Base64Decode::class,
    'base64_encode' => Base64Encode::class,
    'http_build_query' => HttpBuildQuery::class,
    'parse_url' => ParseUrl::class,
    'rawurldecode' => Rawurldecode::class,
    'rawurlencode' => Rawurlencode::class,
    'urldecode' => Urldecode::class,
    'urlencode' => Urlencode::class,
    'boolval' => Boolval::class,
    'doubleval' => Doubleval::class,
    'empty' => _Empty::class,
    'floatval' => Floatval::class,
    'gettype' => Gettype::class,
    'intval' => Intval::class,
    'is_array' => IsArray::class,
    'is_binary' => IsBinary::class,
    'is_bool' => IsBool::class,
    'is_buffer' => IsBuffer::class,
    'is_callable' => IsCallable::class,
    'is_double' => IsDouble::class,
    'is_float' => IsFloat::class,
    'is_int' => IsInt::class,
    'is_integer' => IsInteger::class,
    'is_long' => IsLong::class,
    'is_null' => IsNull::class,
    'is_numeric' => IsNumeric::class,
    'is_object' => IsObject::class,
    'is_real' => IsReal::class,
    'is_scalar' => IsScalar::class,
    'is_string' => IsString::class,
    'is_unicode' => IsUnicode::class,
    'isset' => _Isset::class,
    'print_r' => PrintR::class,
    'serialize' => Serialize::class,
    'strval' => Strval::class,
    'unserialize' => Unserialize::class,
    'var_dump' => VarDump::class,
    'var_export' => VarExport::class,
    'xdiff_string_diff' => XdiffStringDiff::class,
    'xdiff_string_patch' => XdiffStringPatch::class,
    'utf8_decode' => Utf8Decode::class,
    'utf8_encode' => Utf8Encode::class
];
