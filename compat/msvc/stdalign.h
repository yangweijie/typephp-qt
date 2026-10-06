/* MSVC 的 /std:c11 会定义 __STDC_VERSION__，却不自带 <stdalign.h>；
 * php-nano 内置的 ext/hash/xxhash/xxhash.h 在 C11 分支里 include 它 ⇒ fatal error C1083。
 * bin/qtphp 在 Windows + --nano 时把本目录前置到 INCLUDE（见其 $msvcStdalign 分支），
 * 因为 vcvars 会重写 INCLUDE，必须在 call 之后再 set。
 * C++ 模式下 alignas 本就是关键字，这里只补 C 的映射。 */
#ifndef _STDALIGN_H
#define _STDALIGN_H

#ifndef __cplusplus
#define alignas _Alignas
#define alignof _Alignof
#define __alignas_is_defined 1
#define __alignof_is_defined 1
#endif

#endif /* _STDALIGN_H */
