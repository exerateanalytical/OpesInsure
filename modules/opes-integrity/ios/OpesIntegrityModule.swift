import CryptoKit
import DeviceCheck
import ExpoModulesCore
import Foundation

// Contract (src/security/attestation.ts):
//   requestToken(nonce) -> base64(JSON envelope) bound to SHA256(nonce)
//   localSignals()      -> {debuggable, emulator, hooked, rooted} hints; the server decides.
//
// Envelope formats sent to the backend:
//   {"format":"apple-app-attest","stage":"attestation","keyId":..,"attestation":<b64>}  (first use of a key)
//   {"format":"apple-app-attest","stage":"assertion","keyId":..,"assertion":<b64>}      (later uses)
//   {"format":"apple-devicecheck","token":<b64>}                                          (App Attest unavailable)
// clientDataHash = SHA256(UTF-8 nonce).

internal final class IntegrityUnavailableException: Exception {
  override var code: String { "NOT_SUPPORTED" }
  override var reason: String { "Neither App Attest nor DeviceCheck is available on this device" }
}

internal final class IntegrityFailedException: GenericException<String> {
  override var code: String { "TOKEN_FAILED" }
  override var reason: String { "Integrity token request failed: \(param)" }
}

public final class OpesIntegrityModule: Module {
  private static let keyIdDefaultsKey = "opes.integrity.appAttestKeyId"
  private static let attestedDefaultsKey = "opes.integrity.appAttestAttested"

  public func definition() -> ModuleDefinition {
    Name("OpesIntegrity")

    AsyncFunction("requestToken") { (nonce: String) async throws -> String in
      let clientDataHash = Data(SHA256.hash(data: Data(nonce.utf8)))
      if DCAppAttestService.shared.isSupported {
        do {
          return try await self.appAttest(clientDataHash: clientDataHash)
        } catch {
          // Fall through to DeviceCheck.
        }
      }
      if DCDevice.current.isSupported {
        do {
          let token = try await DCDevice.current.generateToken()
          return try Self.envelope(["format": "apple-devicecheck", "token": token.base64EncodedString()])
        } catch {
          throw IntegrityFailedException(error.localizedDescription)
        }
      }
      throw IntegrityUnavailableException()
    }

    AsyncFunction("localSignals") { () -> [String: Bool] in
      return [
        "debuggable": Self.isDebuggerAttached(),
        "emulator": Self.isSimulator(),
        "hooked": Self.isHooked(),
        "rooted": Self.isJailbroken(),
      ]
    }
  }

  private func appAttest(clientDataHash: Data) async throws -> String {
    let service = DCAppAttestService.shared
    let defaults = UserDefaults.standard
    var keyId = defaults.string(forKey: Self.keyIdDefaultsKey)
    if keyId == nil {
      let generated = try await service.generateKey()
      defaults.set(generated, forKey: Self.keyIdDefaultsKey)
      defaults.set(false, forKey: Self.attestedDefaultsKey)
      keyId = generated
    }
    guard let id = keyId else { throw IntegrityUnavailableException() }

    if !defaults.bool(forKey: Self.attestedDefaultsKey) {
      do {
        let attestation = try await service.attestKey(id, clientDataHash: clientDataHash)
        defaults.set(true, forKey: Self.attestedDefaultsKey)
        return try Self.envelope([
          "format": "apple-app-attest", "stage": "attestation", "keyId": id,
          "attestation": attestation.base64EncodedString(),
        ])
      } catch {
        // Key rejected (e.g. invalid key): forget it so the next call starts fresh.
        defaults.removeObject(forKey: Self.keyIdDefaultsKey)
        defaults.removeObject(forKey: Self.attestedDefaultsKey)
        throw error
      }
    }

    do {
      let assertion = try await service.generateAssertion(id, clientDataHash: clientDataHash)
      return try Self.envelope([
        "format": "apple-app-attest", "stage": "assertion", "keyId": id,
        "assertion": assertion.base64EncodedString(),
      ])
    } catch {
      defaults.removeObject(forKey: Self.keyIdDefaultsKey)
      defaults.removeObject(forKey: Self.attestedDefaultsKey)
      throw error
    }
  }

  private static func envelope(_ fields: [String: String]) throws -> String {
    let data = try JSONSerialization.data(withJSONObject: fields, options: [.sortedKeys])
    return data.base64EncodedString()
  }

  // ---- Local heuristics: informative only ----

  private static func isSimulator() -> Bool {
    #if targetEnvironment(simulator)
      return true
    #else
      return false
    #endif
  }

  private static func isDebuggerAttached() -> Bool {
    var info = kinfo_proc()
    var size = MemoryLayout<kinfo_proc>.stride
    var mib: [Int32] = [CTL_KERN, KERN_PROC, KERN_PROC_PID, getpid()]
    let result = sysctl(&mib, UInt32(mib.count), &info, &size, nil, 0)
    return result == 0 && (info.kp_proc.p_flag & P_TRACED) != 0
  }

  private static func isJailbroken() -> Bool {
    #if targetEnvironment(simulator)
      return false
    #else
      let paths = [
        "/Applications/Cydia.app", "/Applications/Sileo.app", "/bin/bash", "/usr/sbin/sshd",
        "/etc/apt", "/private/var/lib/apt/", "/usr/bin/ssh", "/var/jb",
        "/Library/MobileSubstrate/MobileSubstrate.dylib",
      ]
      if paths.contains(where: { FileManager.default.fileExists(atPath: $0) }) { return true }
      let probe = "/private/opes_jb_probe.txt"
      if (try? "x".write(toFile: probe, atomically: true, encoding: .utf8)) != nil {
        try? FileManager.default.removeItem(atPath: probe)
        return true
      }
      return false
    #endif
  }

  private static func isHooked() -> Bool {
    let suspicious = ["frida", "cynject", "substrate", "substitute", "libhooker", "tweakinject"]
    for index in 0..<_dyld_image_count() {
      guard let cName = _dyld_get_image_name(index) else { continue }
      let name = String(cString: cName).lowercased()
      if suspicious.contains(where: { name.contains($0) }) { return true }
    }
    return false
  }
}
