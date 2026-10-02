# WebView2 SDK (vendored)

Microsoft Edge WebView2 SDK, taken from the NuGet package `Microsoft.Web.WebView2`
**version 1.0.4258.31** (<https://www.nuget.org/packages/Microsoft.Web.WebView2>).

Vendored so that building a Windows app with a full-Chromium `webview` needs no extra
download step. Only what a native C++ build requires is kept:

| File | Why |
|---|---|
| `include/WebView2.h` | the COM interfaces |
| `include/WebView2EnvironmentOptions.h` | `ICoreWebView2EnvironmentOptions` implementation |
| `x64/WebView2Loader.dll.lib` | import library (3.5 KB) |
| `x64/WebView2Loader.dll` | the loader itself (195 KB), shipped next to the app |
| `Microsoft.Web.WebView2.nuspec` | provenance: exact package version and license pointer |

The 11 MB `WebView2LoaderStatic.lib` is deliberately **not** vendored — the import library
plus the 195 KB DLL is far smaller and the DLL has to be deployed anyway.

License: the SDK is distributed under Microsoft's terms for the WebView2 SDK; see the
`license`/`projectUrl` entries in the `.nuspec` and <https://aka.ms/webview>.

**The runtime is not here.** WebView2 apps need the *WebView2 Runtime* installed on the
target machine — Windows 10/11 ship it with Edge. It is not redistributable with your app
unless you use Microsoft's Evergreen Bootstrapper or Fixed Version distribution.
