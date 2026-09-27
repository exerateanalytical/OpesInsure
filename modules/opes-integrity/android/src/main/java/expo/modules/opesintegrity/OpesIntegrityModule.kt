package expo.modules.opesintegrity

import android.content.Context
import android.content.pm.ApplicationInfo
import android.os.Build
import android.os.Debug
import com.google.android.play.core.integrity.IntegrityManagerFactory
import com.google.android.play.core.integrity.StandardIntegrityManager.PrepareIntegrityTokenRequest
import com.google.android.play.core.integrity.StandardIntegrityManager.StandardIntegrityTokenProvider
import com.google.android.play.core.integrity.StandardIntegrityManager.StandardIntegrityTokenRequest
import expo.modules.kotlin.Promise
import expo.modules.kotlin.modules.Module
import expo.modules.kotlin.modules.ModuleDefinition
import java.io.File

private typealias ProviderCallback = (StandardIntegrityTokenProvider?, Exception?) -> Unit

/**
 * Contract (src/security/attestation.ts):
 *   requestToken(nonce: string): Promise<string>  -- Play Integrity Standard token, requestHash = server nonce
 *   localSignals(): Promise<{debuggable, emulator, hooked, rooted}>  -- hints only; the server decides
 */
class OpesIntegrityModule : Module() {
  private val lock = Any()
  private var provider: StandardIntegrityTokenProvider? = null
  private var preparing = false
  private val waiting = mutableListOf<ProviderCallback>()

  private val context: Context?
    get() = appContext.reactContext?.applicationContext

  override fun definition() = ModuleDefinition {
    Name("OpesIntegrity")

    // Warm the token provider at app start so the first sensitive action is fast.
    OnCreate {
      if (BuildConfig.PLAY_INTEGRITY_CLOUD_PROJECT_NUMBER > 0L) {
        prepare { _, _ -> }
      }
    }

    AsyncFunction("requestToken") { nonce: String, promise: Promise ->
      if (BuildConfig.PLAY_INTEGRITY_CLOUD_PROJECT_NUMBER <= 0L) {
        promise.reject("NOT_CONFIGURED", "Play Integrity cloud project number was not set at build time", null)
      } else if (nonce.isEmpty() || nonce.length > 500) {
        promise.reject("INVALID_NONCE", "Nonce must be 1..500 characters", null)
      } else {
        requestWithRetry(nonce, promise, false)
      }
    }

    AsyncFunction("localSignals") {
      mapOf(
        "debuggable" to isDebuggable(context),
        "emulator" to isEmulator(),
        "hooked" to isHooked(),
        "rooted" to isRooted()
      )
    }
  }

  private fun requestWithRetry(nonce: String, promise: Promise, retried: Boolean) {
    prepare { ready, error ->
      if (ready == null) {
        promise.reject("PREPARE_FAILED", error?.message ?: "Play Integrity provider unavailable", error)
      } else {
        ready.request(StandardIntegrityTokenRequest.builder().setRequestHash(nonce).build())
          .addOnSuccessListener { response -> promise.resolve(response.token()) }
          .addOnFailureListener { failure ->
            // An expired or invalidated provider is re-prepared once.
            synchronized(lock) {
              if (provider === ready) provider = null
            }
            if (!retried) {
              requestWithRetry(nonce, promise, true)
            } else {
              promise.reject("TOKEN_FAILED", failure.message ?: "Play Integrity token request failed", failure)
            }
          }
      }
    }
  }

  private fun prepare(callback: ProviderCallback) {
    val ctx = context
    if (ctx == null) {
      callback(null, IllegalStateException("No application context"))
      return
    }
    var existing: StandardIntegrityTokenProvider? = null
    var start = false
    synchronized(lock) {
      existing = provider
      if (existing == null) {
        waiting.add(callback)
        if (!preparing) {
          preparing = true
          start = true
        }
      }
    }
    existing?.let {
      callback(it, null)
      return
    }
    if (!start) return
    try {
      IntegrityManagerFactory.createStandard(ctx)
        .prepareIntegrityToken(
          PrepareIntegrityTokenRequest.builder()
            .setCloudProjectNumber(BuildConfig.PLAY_INTEGRITY_CLOUD_PROJECT_NUMBER)
            .build()
        )
        .addOnSuccessListener { ready -> finishPrepare(ready, null) }
        .addOnFailureListener { failure -> finishPrepare(null, failure) }
    } catch (e: Exception) {
      finishPrepare(null, e)
    }
  }

  private fun finishPrepare(ready: StandardIntegrityTokenProvider?, error: Exception?) {
    val callbacks: List<ProviderCallback>
    synchronized(lock) {
      provider = ready
      preparing = false
      callbacks = waiting.toList()
      waiting.clear()
    }
    callbacks.forEach { it(ready, error) }
  }

  // ---- Local heuristics: informative only, never a verdict ----

  private fun isDebuggable(ctx: Context?): Boolean {
    val flag = ctx?.applicationInfo?.let { (it.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0 } ?: false
    return flag || Debug.isDebuggerConnected()
  }

  private fun isEmulator(): Boolean {
    val fp = (Build.FINGERPRINT ?: "").lowercase()
    val model = (Build.MODEL ?: "").lowercase()
    val product = (Build.PRODUCT ?: "").lowercase()
    val hardware = (Build.HARDWARE ?: "").lowercase()
    val brand = (Build.BRAND ?: "").lowercase()
    val device = (Build.DEVICE ?: "").lowercase()
    val manufacturer = (Build.MANUFACTURER ?: "").lowercase()
    return fp.startsWith("generic") || fp.contains("emulator") || fp.contains("vbox") ||
      model.contains("emulator") || model.contains("android sdk built for") || model.contains("sdk_gphone") ||
      manufacturer.contains("genymotion") ||
      (brand.startsWith("generic") && device.startsWith("generic")) ||
      product.contains("sdk_gphone") || product.contains("emulator") || product.contains("simulator") ||
      hardware == "goldfish" || hardware == "ranchu" || hardware.contains("vbox")
  }

  private fun isRooted(): Boolean {
    if (Build.TAGS?.contains("test-keys") == true) return true
    val paths = listOf(
      "/system/bin/su", "/system/xbin/su", "/sbin/su", "/su/bin/su", "/system/su",
      "/data/local/su", "/data/local/bin/su", "/data/local/xbin/su", "/system/sd/xbin/su",
      "/system/app/Superuser.apk", "/data/adb/magisk", "/sbin/.magisk"
    )
    return paths.any { safeExists(it) }
  }

  private fun isHooked(): Boolean {
    val xposed = try {
      Class.forName("de.robv.android.xposed.XposedBridge")
      true
    } catch (_: Throwable) {
      false
    }
    if (xposed) return true
    val maps = try {
      File("/proc/self/maps").readText().lowercase()
    } catch (_: Throwable) {
      ""
    }
    return listOf("frida", "gum-js-loop", "xposed", "lsposed", "substrate").any { maps.contains(it) } ||
      safeExists("/data/local/tmp/frida-server") || safeExists("/data/local/tmp/re.frida.server")
  }

  private fun safeExists(path: String): Boolean = try {
    File(path).exists()
  } catch (_: Throwable) {
    false
  }
}
