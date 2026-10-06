/* php-nano 在 Windows 上没有提供这三个符号的目标文件，链接期缺口在这里补。
 * 整份实现必须包在 PHP_NANO 里：本文件挂在应用的 sources 中，非 nano 构建也会编到它，
 * 那时头文件来自官方 SDK、PHPAPI/ZEND_API 展开成 __declspec(dllimport) —— 在其中定义函数
 * 直接 error C2491，而且这些符号本就由 libphp/phpx 提供，定义还会撞符号。 */
#include "php.h"

#if defined(PHP_NANO)

#include "ext/standard/crc32_x86.h"
#include "ext/random/php_random.h"
#include "Zend/zend_fibers.h"

/* ext/standard/crc32_x86.c 不在 php-nano 的 composer 源码表里，而 Win32 x64 上
 * ZEND_INTRIN_SSE4_2_PCLMUL_RESOLVER 恒为真（zend_portability.h:710），ext/hash/hash_crc32.c
 * 与 ext/standard/crc32.c 的调用点因此没人兜。返回 0 表示 SIMD 没消费任何字节，
 * 调用方退回表驱动的标量循环。 */
size_t crc32_x86_simd_update(X86_CRC32_TYPE type, uint32_t *crc, const unsigned char *p, size_t nr)
{
	return 0;
}

/* Zend/zend_fibers.c 不在源码表里，ext/reflection/php_reflection.c 却引用这个类入口。
 * nano 下 Fiber 类从未注册，留 BSS 空符号即可。 */
zend_class_entry *zend_ce_fiber;

/* 原文是 ext/random/engine_xoshiro256starstar.c 的 PHPAPI inline —— MSVC 的 C11 inline
 * 语义不外发符号，而 ext/random/zend_utils.c 从别的 TU 调它。此处给等价的实体定义。 */
void php_random_xoshiro256starstar_seed256(php_random_status_state_xoshiro256starstar *state,
	uint64_t s0, uint64_t s1, uint64_t s2, uint64_t s3)
{
	state->state[0] = s0;
	state->state[1] = s1;
	state->state[2] = s2;
	state->state[3] = s3;
}

#endif
